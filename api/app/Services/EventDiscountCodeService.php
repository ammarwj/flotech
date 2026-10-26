<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventDiscountCode;
use App\Models\RaceEntry;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The single answer to "may this participant use this code on this event?".
 *
 * Registration and the preview endpoint both go through resolve(), so a code can
 * never be accepted by the preview and then refused at the till — the same
 * reason upgradeOptions() runs the very test the upgrade checkout enforces.
 *
 * Refusals are 422 with a `discount_code` field path, rendered inline under the
 * input. Deliberately NOT `errors.feature`: isPlanLimitError() would swallow it
 * into a toast and the field would never turn red. Each reason gets its own
 * message because the participant can act on the difference — an expired code is
 * nothing they can fix, a quota that just ran out means asking the organizer.
 *
 * **Where this is NOT asked, on purpose:** RegistrationController@store, the
 * organizer's own offline entry. They type whatever amount they mean, so a code
 * there would be a second mechanism for one thing.
 */
class EventDiscountCodeService
{
    /**
     * @param  string|null  $code  what the participant typed; null/empty = no code, and that is the normal case
     * @param  float  $price  the catalogue price BEFORE any fee is computed
     * @param  string|null  $userId  the account registering; both register routes are behind auth:api
     * @return array{code: EventDiscountCode, discount: float, amount: float}|null  null when no code was given
     *
     * @throws ValidationException
     */
    public function resolve(?string $code, Event $event, float $price, ?string $userId): ?array
    {
        $code = $code === null ? '' : EventDiscountCode::normalize($code);

        // The whole feature is optional: no code means nothing about this
        // registration changes.
        if ($code === '') {
            return null;
        }

        // Refused rather than silently ignored. Accepting it would render
        // "diskon diterapkan" above a price of Rp 0, which reads as a discount
        // the participant can no longer find.
        if ($price <= 0) {
            $this->refuse('Pendaftaran ini gratis, jadi kode diskon tidak diperlukan.');
        }

        // Keyed to the account, so a code cannot be spent by someone the
        // once_per_user check below could not recognise. Null only happens if a
        // new caller forgets the auth middleware both register routes carry —
        // refused rather than waved through.
        if ($userId === null) {
            $this->refuse('Masuk ke akunmu dulu untuk memakai kode diskon.');
        }

        /** @var EventDiscountCode|null $row */
        $row = $event->discountCodes()->where('code', $code)->first();

        if (! $row || ! $row->is_active) {
            $this->refuse('Kode diskon tidak ditemukan atau sudah tidak berlaku untuk event ini.');
        }

        $now = Carbon::now();

        if ($row->starts_at && $now->lt($row->starts_at)) {
            $this->refuse('Kode ini baru berlaku '.$this->when($row->starts_at).'.');
        }

        if ($row->ends_at && $now->gt($row->ends_at)) {
            $this->refuse('Kode ini sudah kedaluwarsa pada '.$this->when($row->ends_at).'.');
        }

        if ($row->max_redemptions !== null) {
            $usage = $this->usage($row);

            // `reserved` is load-bearing: without it fifty simultaneous
            // registrations all pass a quota of one and every one of them is
            // then payable. Nothing has to release a reservation — a cancelled,
            // expired, rejected or withdrawn row drops out of the count on its
            // own.
            if ($usage['redeemed'] + $usage['reserved'] >= $row->max_redemptions) {
                $this->refuse('Kuota pemakaian kode ini sudah habis.');
            }
        }

        if ($row->once_per_user && $this->userUsed($row, $userId)) {
            $this->refuse('Kamu sudah memakai kode ini untuk event ini.');
        }

        $discount = $row->discountFor($price);

        return [
            'code' => $row,
            'discount' => $discount,
            'amount' => round($price - $discount, 2),
        ];
    }

    /**
     * Redemptions and live reservations across BOTH participant rails.
     *
     * The gate above and EventDiscountCodeResource both read this, so the number
     * the organizer sees cannot disagree with the number that refuses a
     * registration.
     *
     * Two queries folded once, deliberately not a UNION: a UNION needs
     * placeholder columns on both sides — the nullable-pair shape the separate
     * tables exist to avoid, rebuilt in its reader. Same call as
     * DisciplineService::cards().
     *
     * **A stated gap:** `teams` has no expire-manual sweep the way
     * `race_entries` does, so an abandoned team registration holds its quota
     * slot until the organizer rejects it or the manager withdraws. That is a
     * valve the organizer already has, and a third sweep here would be a second
     * writer over registration quota.
     *
     * @return array{redeemed: int, reserved: int}
     */
    public function usage(EventDiscountCode $code): array
    {
        // `case when`, not Postgres' `filter`: the test suite runs on sqlite,
        // and a gate that only compiles on one driver is a gate nothing can
        // test.
        $teams = Team::query()
            ->where('event_discount_code_id', $code->id)
            ->selectRaw("sum(case when payment_status = 'paid' then 1 else 0 end) as redeemed")
            // A team the organizer rejected, or one that withdrew, is holding
            // nothing — same reading as a cancelled entry below.
            ->selectRaw("sum(case when payment_status = 'unpaid' and status not in ('rejected', 'withdrawn') then 1 else 0 end) as reserved")
            ->first();

        $entries = RaceEntry::query()
            ->where('event_discount_code_id', $code->id)
            ->selectRaw("sum(case when status = 'paid' then 1 else 0 end) as redeemed")
            ->selectRaw("sum(case when status = 'pending' then 1 else 0 end) as reserved")
            ->first();

        return [
            'redeemed' => (int) ($teams->redeemed ?? 0) + (int) ($entries->redeemed ?? 0),
            'reserved' => (int) ($teams->reserved ?? 0) + (int) ($entries->reserved ?? 0),
        ];
    }

    /**
     * Has this account already taken this code?
     *
     * An unpaid row counts: an outstanding registration is a claim on the code,
     * and letting a second one through would hand one person two discounts for
     * one allowance. A cancelled, rejected or withdrawn attempt frees it again,
     * the same mechanism as `reserved` above.
     */
    private function userUsed(EventDiscountCode $code, string $userId): bool
    {
        $team = Team::query()
            ->where('event_discount_code_id', $code->id)
            ->where('manager_user_id', $userId)
            ->whereNotIn('status', ['rejected', 'withdrawn'])
            ->exists();

        if ($team) {
            return true;
        }

        return RaceEntry::query()
            ->where('event_discount_code_id', $code->id)
            ->where('user_id', $userId)
            ->whereIn('status', ['paid', 'pending'])
            ->exists();
    }

    /** Venue time, like every other participant-facing timestamp. */
    private function when(Carbon $at): string
    {
        return $at->timezone(config('wallet.timezone'))->locale('id')->translatedFormat('d F Y H:i');
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['discount_code' => [$message]]);
    }
}
