<?php

namespace App\Services;

use App\Models\DiscountCode;
use App\Models\EventPlanOrder;
use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The single answer to "may this organization use this code on this plan?".
 *
 * Checkout and the preview endpoint both go through resolve(), so a code can
 * never be accepted by the preview and then refused at the till — the same
 * reason upgradeOptions() runs the very test checkoutUpgrade() enforces.
 *
 * Refusals are 422 with a `discount_code` field path, rendered inline under the
 * input. Deliberately NOT `errors.feature`: isPlanLimitError() would swallow it
 * into a toast and the field would never turn red. Each reason gets its own
 * message because the organizer can act on the difference — an expired code is
 * nothing they can fix, a code that is simply not valid for this plan is.
 */
class DiscountCodeService
{
    /**
     * @return array{code: DiscountCode, discount: float, amount: float}|null  null when no code was given
     *
     * @throws ValidationException
     */
    public function resolve(?string $code, Organization $org, Plan $plan): ?array
    {
        $code = $code === null ? '' : DiscountCode::normalize($code);

        if ($code === '') {
            return null;
        }

        /** @var DiscountCode|null $row */
        $row = DiscountCode::where('code', $code)->first();

        if (! $row || ! $row->is_active) {
            $this->refuse('Kode diskon tidak ditemukan atau sudah tidak berlaku.');
        }

        $now = Carbon::now();

        if ($row->starts_at && $now->lt($row->starts_at)) {
            $this->refuse('Kode ini baru berlaku '.$row->starts_at->timezone(config('wallet.timezone'))->locale('id')->translatedFormat('d F Y H:i').'.');
        }

        if ($row->ends_at && $now->gt($row->ends_at)) {
            $this->refuse('Kode ini sudah kedaluwarsa pada '.$row->ends_at->timezone(config('wallet.timezone'))->locale('id')->translatedFormat('d F Y H:i').'.');
        }

        // Empty pivot = every plan. See DiscountCode::plans().
        if ($row->plans()->exists() && ! $row->plans()->whereKey($plan->id)->exists()) {
            $this->refuse("Kode ini tidak berlaku untuk paket {$plan->name}.");
        }

        if ($row->max_redemptions !== null) {
            $usage = $this->usage($row);

            // `reserved` is load-bearing: without it fifty simultaneous
            // checkouts all pass the gate on a quota of one, and every one of
            // them is then payable. Nothing has to release a reservation — the
            // Midtrans webhook (expire/deny/cancel) and
            // plan-orders:expire-manual already write `cancelled`, which drops
            // the row out of this count on its own.
            if ($usage['redeemed'] + $usage['reserved'] >= $row->max_redemptions) {
                $this->refuse('Kuota pemakaian kode ini sudah habis.');
            }
        }

        if ($row->once_per_organization && $this->organizationUsed($row, $org)) {
            $this->refuse('Organisasi Anda sudah memakai kode ini.');
        }

        $price = (float) $plan->price;
        $discount = $row->discountFor($price);

        return [
            'code' => $row,
            'discount' => $discount,
            'amount' => round($price - $discount, 2),
        ];
    }

    /**
     * Redemptions and live reservations, in one query.
     *
     * The gate above and DiscountCodeResource both read this, so the number
     * shown in /admin/discount-codes cannot disagree with the number that
     * refuses a checkout.
     *
     * @return array{redeemed: int, reserved: int}
     */
    public function usage(DiscountCode $code): array
    {
        $row = EventPlanOrder::query()
            ->where('discount_code_id', $code->id)
            // `case when`, not Postgres' `filter`: the test suite runs on
            // sqlite, and a gate that only compiles on one driver is a gate
            // nothing can test.
            ->selectRaw("sum(case when status = 'paid' then 1 else 0 end) as redeemed")
            ->selectRaw("sum(case when status = 'past_due' then 1 else 0 end) as reserved")
            ->first();

        return [
            'redeemed' => (int) ($row->redeemed ?? 0),
            'reserved' => (int) ($row->reserved ?? 0),
        ];
    }

    /**
     * Has this organization already taken this code?
     *
     * `past_due` counts: an outstanding invoice is a claim on the code, and
     * letting a second one through would hand the same organization two
     * discounts for one allowance. A cancelled or expired attempt frees it
     * again, same mechanism as `reserved` above.
     */
    private function organizationUsed(DiscountCode $code, Organization $org): bool
    {
        return EventPlanOrder::query()
            ->where('discount_code_id', $code->id)
            ->where('organization_id', $org->id)
            ->whereIn('status', ['paid', 'past_due'])
            ->exists();
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['discount_code' => [$message]]);
    }
}
