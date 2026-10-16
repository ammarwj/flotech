<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Mail\RaceEntryPaidMail;
use App\Models\RaceCategory;
use App\Models\RaceEntry;
use App\Models\User;
use App\Support\RacePricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fun run entry lifecycle: hold a place, settle it, release it.
 *
 * Twin of TicketService and RegistrationService, and the fourth thing a
 * participant can pay for. The three differ in what is being bought and agree
 * on everything else, which is why the shapes are deliberately parallel: a
 * reader who knows one knows all of them, and a rule that holds for one
 * (manual money never touches the wallet, a free order gets no document, a
 * re-delivered webhook is a no-op) is spelled the same way here.
 *
 * The one rule worth stating up front: a check-in credential is minted on
 * settlement and never before — see hold() and markPaid().
 */
class RaceEntryService
{
    public function __construct(
        protected WalletService $wallet,
        protected DocumentNumberService $numbers,
        protected RacePricing $pricing,
    ) {}

    /** @param  'invoice'|'receipt'  $kind */
    protected function number(string $kind): string
    {
        return $this->numbers->next(
            RaceEntry::class,
            "{$kind}_number",
            config("billing.race_{$kind}_prefix"),
        );
    }

    /**
     * Hold a place for one runner.
     *
     * The price is resolved HERE and the answer is stored, never read back
     * through `race_period_id`. Organizers edit period prices mid-sale, and a
     * bill already issued must not change what it says — the same snapshot
     * reasoning as `withdrawals` copying the bank account and every order
     * copying its `payment_method`.
     *
     * `$at` exists for the tests and for nothing else: the clock that picks the
     * period is the server's, never a parameter a client can send. Defaulting
     * it here rather than accepting a period id is what makes "give me the
     * early-bird price" unaskable.
     *
     * **It must not fill `qr_code`.** Minting a code before the money arrives
     * issues a check-in credential for an unpaid entry, and taking it back
     * later means deciding what happens to codes already handed out.
     * `receipt_number` avoids exactly that decision by being born only on
     * settlement, and markPaid() mints the QR the same way for the same reason.
     *
     * @param  array{full_name: string, email: string, phone?: string|null, gender?: string|null, birth_date?: string|null, emergency_contact_name?: string|null, emergency_contact_phone?: string|null, custom_fields?: array<string, mixed>|null}  $runner
     *
     * @throws PaymentException when nothing is on sale, or this category is not
     */
    public function hold(
        RaceCategory $category,
        array $runner,
        string $orderId,
        ?string $userId = null,
        string $paymentMethod = 'gateway',
        ?Carbon $deadline = null,
        ?string $paymentChannel = null,
        float $gatewayFee = 0.0,
        float $serviceFee = 0.0,
        float $gatewayTax = 0.0,
        ?Carbon $at = null,
    ): RaceEntry {
        $quote = $this->pricing->quote($category, $at);
        $price = $quote['price'];

        return DB::transaction(function () use ($category, $runner, $orderId, $userId, $quote, $price, $paymentMethod, $deadline, $paymentChannel, $gatewayFee, $serviceFee, $gatewayTax) {
            // The reservation, mirroring TicketService: an unpaid entry really
            // is holding a place, which is what race-entries:expire-manual
            // gives back when nobody pays for it.
            $category->increment('taken');

            return $category->entries()->create([
                'event_id' => $category->event_id,
                'user_id' => $userId,
                'full_name' => $runner['full_name'],
                'email' => $runner['email'],
                'phone' => $runner['phone'] ?? null,
                'gender' => $runner['gender'] ?? null,
                'birth_date' => $runner['birth_date'] ?? null,
                'emergency_contact_name' => $runner['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $runner['emergency_contact_phone'] ?? null,
                'custom_fields' => $runner['custom_fields'] ?? null,

                // The snapshot. The FK is for display and may go null; these
                // two are what the receipt reads.
                'race_period_id' => $quote['period']->id,
                'period_name' => $quote['period']->name,
                'price' => $price,

                // A free entry has no bill, so it gets no document — a Rp 0
                // invoice claims a transaction that never happened.
                'invoice_number' => $price > 0 ? $this->number('invoice') : null,
                // Manual money never reaches us, so there is nothing to take a
                // cut of; and on the gateway rail the buyer-paid fees replaced
                // this column entirely. Always 0, like ticket_orders.
                'platform_fee' => 0,
                'status' => 'pending',
                'payment_method' => $paymentMethod,
                'payment_channel' => $paymentChannel,
                'gateway_fee' => $gatewayFee,
                // Already inside gateway_fee; stored so the PDF can show the
                // tax as its own line without recomputing a rate that moves.
                'gateway_tax' => $gatewayTax,
                'service_fee' => $serviceFee,
                'payment_deadline_at' => $deadline,
                'midtrans_order_id' => $orderId,
            ]);
        });
    }

    /**
     * Settle a paid entry and credit the organizer. Idempotent — re-delivered
     * webhooks are no-ops, and the early return is also what keeps the runner
     * from getting a second mail.
     */
    public function markPaid(RaceEntry $entry): void
    {
        if ($entry->status === 'paid') {
            return;
        }

        DB::transaction(function () use ($entry) {
            $entry->update([
                'status' => 'paid',
                'paid_at' => Carbon::now(),
                // `??` keeps a re-delivered webhook from issuing a second
                // receipt for one payment; free entries never get one at all.
                'receipt_number' => $entry->receipt_number
                    ?? ((float) $entry->price > 0 ? $this->number('receipt') : null),
                // The check-in credential, born here and nowhere earlier. The
                // same `??` for the same reason as the receipt above: a
                // re-delivered webhook must not mint a second code and silently
                // invalidate the one already on the runner's phone.
                //
                // Unlike the receipt it is issued for a FREE entry too — a
                // sponsored run still has a start line, and `price > 0` is a
                // question about billing, not about admission.
                'qr_code' => $entry->qr_code ?? self::newQrCode(),
            ]);

            // A manual transfer went straight into the organizer's own bank
            // account — the money never passed through us, so crediting the
            // wallet would make their balance claim funds we are not holding.
            // Same reasoning, same guard as TicketService::markPaid().
            //
            // isOffPlatform() rather than isManual() for consistency with the
            // other two rails, even though an entry can never be `onsite`
            // today: the two answer identically here, and the day a race grows
            // an on-the-day registration desk this line is already right.
            if (! $entry->isOffPlatform()) {
                $this->wallet->creditRaceEntry($entry->load('event.organization'));
            }
        });

        $this->sendConfirmation($entry->fresh());
    }

    /**
     * Queue the runner's confirmation mail. Deliberately swallows its own
     * errors: the payment is already settled, so a mail/queue hiccup must not
     * bubble up into the Midtrans webhook and provoke a retry. Same reasoning
     * as TicketService and RegistrationService.
     */
    protected function sendConfirmation(RaceEntry $entry): void
    {
        try {
            Mail::to($entry->email)->queue(
                new RaceEntryPaidMail($entry->load(['event', 'category']))
            );
        } catch (Throwable $e) {
            Log::error('Gagal mengirim email konfirmasi entry lomba', [
                'race_entry_id' => $entry->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The runner uploads their transfer receipt for an org admin to check.
     *
     * @throws PaymentException
     */
    public function submitProof(RaceEntry $entry, string $proofUrl): void
    {
        if (! $entry->isManual()) {
            throw new PaymentException('Entry ini tidak dibayar lewat transfer manual.');
        }

        if ($entry->isSettled()) {
            throw new PaymentException('Entry ini sudah dibayar.');
        }

        $entry->attachProof($proofUrl);
    }

    /**
     * Accept a manual transfer. markPaid() is what keeps this off the wallet —
     * the money went to the organizer's own account, not ours.
     *
     * @throws PaymentException
     */
    public function approveProof(RaceEntry $entry, User $admin): void
    {
        if (! $entry->isAwaitingVerification()) {
            throw new PaymentException('Tidak ada bukti pembayaran yang menunggu verifikasi.');
        }

        $entry->markVerified($admin);
        $this->markPaid($entry->fresh());
    }

    /**
     * @throws PaymentException
     */
    public function rejectProof(RaceEntry $entry, string $reason, Carbon $deadline): void
    {
        if (! $entry->isAwaitingVerification()) {
            throw new PaymentException('Tidak ada bukti pembayaran yang menunggu verifikasi.');
        }

        $entry->rejectProof($reason, $deadline);
    }

    /**
     * Cancel an unpaid entry and give its place back.
     *
     * The guard is the same by-value list the other two services carry, and for
     * the same reason: a paid, cancelled or refunded entry has already released
     * (or must not release) its place, and running this twice would hand back a
     * place nobody was holding. Refunds do not come through here — they are
     * RefundService's, because reversing the ledger is its job.
     */
    public function cancel(RaceEntry $entry, string $status = 'cancelled'): void
    {
        if (in_array($entry->status, ['paid', 'cancelled', 'refunded'], true)) {
            return;
        }

        DB::transaction(function () use ($entry, $status) {
            $entry->update(['status' => $status]);
            $entry->category()->decrement('taken');
        });
    }

    /**
     * A check-in credential.
     *
     * `RUN-` prefixed and a lowercase ULID, the shape TicketService uses for
     * `TIX-`: unguessable, sortable, and one glance tells gate staff which kind
     * of code they are holding when they read it off a screen.
     */
    public static function newQrCode(): string
    {
        return 'RUN-'.Str::lower(Str::ulid());
    }

    /**
     * Give a paid entry its QR if it predates this feature.
     *
     * Phase 1 settled entries without a code, so every entry paid before this
     * shipped would be refused at the registration desk. Minted on read rather
     * than by a backfill command: the organizer's own answer was that a command
     * is one more thing to remember, and the proof page is the only place the
     * code is ever needed — if nobody opens it, nobody is checking in with it.
     *
     * **The conditional UPDATE is what makes this safe, and `lockForUpdate`
     * would not be.** The proof page polls every four seconds and a runner may
     * have it open on two devices, so two readers can arrive together; without
     * `whereNull('qr_code')` the second write would replace a code the first
     * reader has already rendered, and the QR on screen would stop scanning
     * with nothing to show why. Same shape, same reason as the atomic credit
     * claim in EventController::store().
     *
     * Deliberately NOT called from the organizer's list or the verification
     * queue: a read path that writes is one that cannot be cached, and those
     * two are read far more often than they are acted on.
     */
    public function ensureQrCode(RaceEntry $entry): RaceEntry
    {
        if ($entry->status !== 'paid' || $entry->qr_code !== null) {
            return $entry;
        }

        RaceEntry::whereKey($entry->id)
            ->whereNull('qr_code')
            ->update(['qr_code' => self::newQrCode()]);

        // Re-read rather than trusting the write: on the losing side of a race
        // the update matched nothing, and the code that counts is the winner's.
        return $entry->fresh();
    }

    /**
     * Check a runner in at the start line. The only place those three columns
     * are written, so a second door — a desk app, a bulk tool — cannot disagree
     * about what "checked in" means. Same reasoning as TicketService::checkIn().
     */
    public function checkIn(RaceEntry $entry, ?string $userId): void
    {
        $entry->update([
            'checked_in_at' => Carbon::now(),
            'checked_in_by' => $userId,
        ]);
    }

    /**
     * A fresh Midtrans order id for this entry.
     *
     * `RUN-` is load-bearing: MidtransWebhookController routes on the prefix,
     * and its `default` arm is the plan-order path. An id minted without this
     * prefix settles nothing and answers Midtrans with a 404.
     */
    public function newOrderId(): string
    {
        return 'RUN-'.Str::upper(Str::random(10));
    }
}
