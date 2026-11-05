<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Mail\RaceEntryPaidMail;
use App\Models\Event;
use App\Models\RaceCategory;
use App\Models\RaceEntry;
use App\Models\User;
use App\Support\RacePricing;
use App\Support\RegistrationForm;
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
     * @param  array{full_name: string, email: string, phone?: string|null, gender?: string|null, birth_date?: string|null, emergency_contact_name?: string|null, emergency_contact_phone?: string|null, emergency_contact_relation?: string|null, custom_fields?: array<string, mixed>|null}  $runner
     * @param  array{code: \App\Models\EventDiscountCode, discount: float, amount: float}|null  $discount  already resolved by the controller
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
        ?array $discount = null,
    ): RaceEntry {
        $quote = $this->pricing->quote($category, $at);
        // Net of the discount the controller already resolved. Resolved THERE
        // rather than here because it has to happen before the fee breakdown,
        // which is also the controller's — and because a refused code must burn
        // no invoice number, the same reason the quote itself is taken before
        // anything is written.
        $price = $discount ? $discount['amount'] : $quote['price'];

        return DB::transaction(function () use ($category, $runner, $orderId, $userId, $quote, $price, $paymentMethod, $deadline, $paymentChannel, $gatewayFee, $serviceFee, $gatewayTax, $discount) {
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
                'emergency_contact_relation' => $runner['emergency_contact_relation'] ?? null,
                'custom_fields' => $runner['custom_fields'] ?? null,

                // The snapshot. The FK is for display and may go null; these
                // two are what the receipt reads.
                'race_period_id' => $quote['period']->id,
                'period_name' => $quote['period']->name,
                // Net of any discount. That net is also what the wallet is
                // credited (WalletService::creditRaceEntry reads this column),
                // which is how "the organizer bears the discount" holds with no
                // second rule anywhere.
                'price' => $price,

                // The discount snapshot, beside the FK for the same reason the
                // period's name sits beside `race_period_id`: the code may be
                // edited or deleted, and a receipt already issued must not
                // change what it says.
                'event_discount_code_id' => $discount['code']->id ?? null,
                'discount_code' => $discount['code']->code ?? null,
                'discount_type' => $discount['code']->discount_type ?? null,
                'discount_value' => $discount['code']->discount_value ?? null,
                'discount_amount' => $discount['discount'] ?? 0,

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
    public function markPaid(RaceEntry $entry, ?string $paymentType = null): void
    {
        if ($entry->status === 'paid') {
            return;
        }

        DB::transaction(function () use ($entry, $paymentType) {
            $entry->update([
                'status' => 'paid',
                'paid_at' => Carbon::now(),
                // The Midtrans instrument, known only to the webhook. Written
                // with `??` so the other doors into this method — manual
                // approval, a free entry, dev's mock settle — cannot blank a
                // value a settlement already stored. Same as the other rails.
                'payment_type' => $paymentType ?? $entry->payment_type,
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

    // ---- refund requests ----

    /**
     * The runner asks for their entry fee back.
     *
     * Only the asking lives here. Granting one reverses a wallet credit, and
     * `RefundService` is the only thing allowed to do that — the same split
     * `approveProof()` keeps with `markPaid()`, and the reason this method can
     * sit in a service that knows nothing about the ledger.
     *
     * The four conditions are `RaceEntry::canRequestRefund()`, asked here and
     * published to the client from the same method, so the button and the guard
     * cannot drift. The messages differ per cause because the runner can act on
     * the difference: an unpaid entry is cancelled rather than refunded, a free
     * one has nothing to give back, a checked-in one already ran, and a second
     * request is simply the first one still waiting.
     *
     * @throws PaymentException
     */
    public function requestRefund(RaceEntry $entry, string $reason): void
    {
        if ($entry->status !== 'paid') {
            throw new PaymentException('Hanya pendaftaran lunas yang bisa diajukan refund.');
        }

        if ((float) $entry->price <= 0) {
            throw new PaymentException('Pendaftaran gratis tidak bisa direfund.');
        }

        if ($entry->checked_in_at !== null) {
            throw new PaymentException('Pendaftaran ini sudah check-in di lokasi, tidak bisa direfund.');
        }

        if ($entry->hasOpenRefundRequest()) {
            throw new PaymentException('Pengajuan refund kamu masih menunggu keputusan penyelenggara.');
        }

        // The decision columns are cleared, which is what lets a runner ask
        // again after a refusal — the same shape as `rejectProof()` clearing
        // `payment_proof_url` to put an order back in the queue. A refusal is a
        // verdict on this request, not on the entry.
        $entry->update([
            'refund_reason' => $reason,
            'refund_requested_at' => Carbon::now(),
            'refund_decided_at' => null,
            'refund_decided_by' => null,
            'refund_rejected_reason' => null,
        ]);
    }

    /**
     * Turn a request down. The entry stays paid and keeps its place.
     *
     * `refund_requested_at` is deliberately LEFT standing: the runner's page
     * reads it beside `refund_rejected_reason` to say "kamu mengajukan tanggal
     * ini, ditolak karena ini". Clearing it would erase the question the answer
     * belongs to, and the organizer's own history of having been asked.
     *
     * @throws PaymentException
     */
    public function rejectRefund(RaceEntry $entry, User $admin, string $reason): void
    {
        if (! $entry->hasOpenRefundRequest()) {
            throw new PaymentException('Tidak ada pengajuan refund yang menunggu keputusan.');
        }

        $entry->update([
            'refund_decided_at' => Carbon::now(),
            'refund_decided_by' => $admin->id,
            'refund_rejected_reason' => $reason,
        ]);
    }

    /**
     * Stamp the verdict half of an approval.
     *
     * Called by the controller INSIDE the same transaction as
     * `RefundService::refundRaceEntry()`, never on its own: a row marked decided
     * whose ledger was not reversed is an organizer credited for money that went
     * back, which `wallet:audit` cannot see. The reversal is the refund; this is
     * only the record of who said yes.
     */
    public function markRefundApproved(RaceEntry $entry, User $admin): void
    {
        $entry->update([
            'refund_decided_at' => Carbon::now(),
            'refund_decided_by' => $admin->id,
            'refund_rejected_reason' => null,
        ]);
    }

    /**
     * Stamp the verdict half of a refund the ORGANIZER started.
     *
     * Called inside the same transaction as `RefundService::refundRaceEntry()`,
     * exactly like `markRefundApproved()` above and for the same reason.
     *
     * > **Invariant: `refund_requested_at` is left NULL, and that null is what
     * > says who started this.** It is the only thing separating "the runner
     * > asked and we agreed" from "we refunded them without being asked", and
     * > two screens act on the difference: the runner's own page cannot say
     * > "penyelenggara menyetujui pengajuanmu" over a refund they never asked
     * > for, and the organizer's queue must not show a decided row as an answer
     * > to a question nobody put. Stamping it here — which would make this
     * > method one line shorter — is how a refund grows a request that never
     * > happened. Test it by COMPARING the two paths in one test: assert the
     * > issued one has `refund_requested_at` null while the approved one does
     * > not; asserting `status === 'refunded'` holds on both and proves nothing.
     *
     * `refund_reason` is written either way, and the author is read off that
     * same null rather than from a second column: one reason per refund, whoever
     * typed it, and a `refund_initiated_by` beside it would be a second
     * spelling of a fact this one already carries.
     */
    public function markRefundIssued(RaceEntry $entry, User $admin, string $reason): void
    {
        $entry->update([
            'refund_reason' => $reason,
            'refund_decided_at' => Carbon::now(),
            'refund_decided_by' => $admin->id,
            'refund_rejected_reason' => null,
        ]);
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

    // ---- the event's own registration form ----

    /**
     * Which required answers and documents are missing, keyed by field path.
     *
     * Here rather than in a controller because there are now TWO doors onto a
     * race entry's answers — the runner's public form and the organizer's edit
     * — and the codebase already carries this pair twice (TeamRosterService for
     * teams, here for runners) with a note saying to touch both. A third copy
     * is how one door starts demanding a field the other does not.
     *
     * Empty array = complete. The caller shapes the refusal, because the two
     * differ: the public form answers 422 with these paths, and the organizer's
     * does the same — but only the caller knows which.
     *
     * @param  array<string, mixed>  $answers
     * @param  list<string>  $uploadedTypes  document slot keys already in hand
     * @return array<string, string>
     */
    public function formErrors(Event $event, array $answers, array $uploadedTypes): array
    {
        $form = RegistrationForm::forEvent($event);
        $errors = [];

        foreach ($form->teamFields as $field) {
            if (! ($field['required'] ?? false)) {
                continue;
            }

            // A question this runner was never shown is not one they skipped.
            if (! RegistrationForm::isVisible($field, $answers)) {
                continue;
            }

            // Consent goes through normalizeAnswer() so "ticked" is decided in
            // one place; its stored value is an instant the server writes, and
            // the payload's own text is never what is checked.
            $value = $field['type'] === 'consent'
                ? RegistrationForm::normalizeAnswer('consent', $answers[$field['key']] ?? null)
                : $answers[$field['key']] ?? null;

            if (! is_scalar($value) || trim((string) $value) === '') {
                $errors["custom_fields.{$field['key']}"] = $field['type'] === 'consent'
                    ? "{$field['label']} wajib disetujui."
                    : "{$field['label']} wajib diisi.";
            }
        }

        foreach ($form->teamDocuments as $doc) {
            if (($doc['required'] ?? false) && ! in_array($doc['key'], $uploadedTypes, true)) {
                $errors['documents'] = "Dokumen \"{$doc['label']}\" wajib diunggah.";
            }
        }

        return $errors;
    }

    /**
     * Keep only the answers this event actually asks for.
     *
     * Mirrors TeamRosterService::cleanAnswers(), and lives beside
     * formErrors() for the reason written there.
     *
     * Hidden means not stored, not merely not demanded — one without the other
     * leaves a NIM on a runner who ended up picking UMUM.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>|null
     */
    public function cleanAnswers(Event $event, array $answers): ?array
    {
        $clean = [];

        foreach (RegistrationForm::forEvent($event)->teamFields as $field) {
            if (! RegistrationForm::isVisible($field, $answers)) {
                continue;
            }

            $value = RegistrationForm::normalizeAnswer(
                $field['type'],
                $answers[$field['key']] ?? null,
            );

            if ($value !== null) {
                $clean[$field['key']] = $value;
            }
        }

        return $clean === [] ? null : $clean;
    }
}
