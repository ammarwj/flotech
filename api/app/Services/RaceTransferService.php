<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Models\RaceCategory;
use App\Models\RaceEntry;
use App\Models\RaceEntryTransfer;
use App\Models\User;
use App\Support\RacePricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moving a runner's entry from one race category to another.
 *
 * The only thing allowed to write `race_entries.race_category_id` after an
 * entry exists, and the only thing allowed to move `race_categories.taken`
 * between two categories. `RaceEntryService` owns the single-category half of
 * that column (hold / cancel); a second mover that did not go through one place
 * would hand back a place nobody was holding, which is the hazard that already
 * put `cancel()` in a service instead of a controller.
 *
 * > **Invariant: `race_entries.price` is never rewritten.** The invoice and the
 * > receipt are rendered ON DEMAND from the row, so rewriting it changes a
 * > document somebody already downloaded. What a move costs is its own bill on
 * > its own row, and `RaceEntryTransfer::paidTowards()` is what adds them up —
 * > see the migration.
 *
 * > **Invariant: the prices come from the ENTRY's sale window.** `quote()` asks
 * > `RacePricing::priceFor($category, $entry->period)` and refuses outright when
 * > that window is gone. A runner who bought in Early Bird moves at Early Bird
 * > prices; falling through to today's window would charge them for waiting,
 * > and falling through to "closest window" would invent a number nobody can
 * > point at an approval for — the reading `RacePricing` already refuses for a
 * > gap between windows.
 */
class RaceTransferService
{
    public function __construct(
        protected WalletService $wallet,
        protected DocumentNumberService $numbers,
        protected RacePricing $pricing,
        protected RefundService $refunds,
    ) {}

    /**
     * What moving to this category would cost, or give back.
     *
     * Read before anything is written, so a refused move burns no invoice
     * number — the reason `platformDestination()` is planless and the reason
     * every other rail takes its quote first. The same method answers the
     * preview endpoint and the create endpoint, so no option can be offered on
     * screen and then refused at the till: the `upgradeOptions()` pattern.
     *
     * @return array{direction: string, amount: float, from_price: float, to_price: float, paid_before: float, period_name: string}
     *
     * @throws PaymentException
     */
    public function quote(RaceEntry $entry, RaceCategory $target): array
    {
        $period = $entry->period;

        if (! $period) {
            // The window this entry was bought in has been deleted, so there is
            // no price list to compare against. Refused rather than quoted off
            // today's window: that charges a runner for the organizer having
            // tidied up, and the difference could be any size.
            throw new PaymentException(
                'Periode pendaftaran entry ini sudah tidak ada, jadi harga pindahnya tidak bisa dihitung. Hubungi penyelenggara.',
            );
        }

        $from = $entry->category;

        if ($target->id === $from?->id) {
            throw new PaymentException(
                'Kategori tujuan sama dengan kategori sekarang.',
                ['race_category_id' => ['Pilih kategori yang berbeda.']],
            );
        }

        if (! $target->is_active) {
            throw new PaymentException(
                'Kategori tujuan sedang tidak dibuka.',
                ['race_category_id' => ['Kategori ini sedang tidak dibuka.']],
            );
        }

        if ($target->event_id !== $entry->event_id) {
            // Not a 404: the id is real, it just belongs to another race. A
            // move between events would carry a receipt, a QR and a wallet
            // credit across to an organizer who never sold the place.
            throw new PaymentException(
                'Kategori tujuan bukan milik event ini.',
                ['race_category_id' => ['Kategori tidak valid.']],
            );
        }

        if ($target->remaining() !== null && $target->remaining() <= 0) {
            throw new PaymentException(
                'Kuota kategori tujuan sudah penuh.',
                ['race_category_id' => ['Kuota kategori ini sudah penuh.']],
            );
        }

        // The email condition belongs to the category, so it has to be asked
        // again here: a runner may not enter UMUM-only prices into a student
        // category by the side door, and the organizer's own list is where that
        // condition is enforced for everybody else.
        if (! $target->acceptsEmail($entry->email)) {
            $allowed = implode(', ', array_map(
                fn (string $domain) => '@'.$domain,
                $target->emailDomains(),
            ));

            throw new PaymentException(
                "Kategori ini hanya untuk pendaftar dengan email {$allowed}.",
                ['race_category_id' => ['Email pendaftaranmu tidak memenuhi syarat kategori ini.']],
            );
        }

        $toPrice = $this->pricing->priceFor($target, $period);

        if ($toPrice === null) {
            // Null is not zero — see RacePricing::priceFor(). A category with
            // no row for this window was not on sale then, and moving into it
            // at a price nobody set is how somebody enters for free.
            throw new PaymentException(
                "Kategori ini tidak dijual pada periode {$period->name}.",
                ['race_category_id' => ['Kategori ini tidak tersedia pada periode pendaftaranmu.']],
            );
        }

        // The catalogue price of the category being LEFT, not `race_entries.price`:
        // that column is net of any discount, and the row has to be readable a
        // year later as "100rb → 200rb" rather than "50rb → 200rb".
        $fromPrice = $from ? ($this->pricing->priceFor($from, $period) ?? (float) $entry->price) : (float) $entry->price;

        // Everything the runner has actually handed over, this entry's own
        // price plus every settled move. See RaceEntryTransfer::paidTowards()
        // for why `price` alone overcharges the second move.
        $paid = RaceEntryTransfer::paidTowards($entry);
        $difference = round($toPrice - $paid, 2);

        return [
            'direction' => match (true) {
                $difference > 0 => RaceEntryTransfer::UP,
                $difference < 0 => RaceEntryTransfer::DOWN,
                default => RaceEntryTransfer::EVEN,
            },
            // Always positive; `direction` carries the sign. The shape
            // `wallet_transactions` uses, for the reason given there.
            'amount' => abs($difference),
            'from_price' => $fromPrice,
            'to_price' => $toPrice,
            'paid_before' => $paid,
            'period_name' => $period->name,
        ];
    }

    /**
     * Every category this entry could move to, each with its own quote.
     *
     * Built from the same `quote()` the create endpoint calls, so a category
     * cannot be offered here and refused there — the `upgradeOptions()` pattern
     * again. A category that fails any of the guards is simply absent rather
     * than listed with an error: the runner cannot act on "full" or "not sold in
     * your window" beyond picking something else, which is what the list is for.
     *
     * @return list<array{category: RaceCategory, quote: array<string, mixed>}>
     */
    public function options(RaceEntry $entry): array
    {
        $entry->loadMissing(['event.racePeriods', 'category.prices', 'transfers']);

        $categories = $entry->event->raceCategories()
            ->with(['prices', 'event.racePeriods'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $options = [];

        foreach ($categories as $category) {
            try {
                $options[] = ['category' => $category, 'quote' => $this->quote($entry, $category)];
            } catch (PaymentException) {
                // Not offerable. See the docblock.
                continue;
            }
        }

        return $options;
    }

    /**
     * Start a move: record it, and take the place in the target category.
     *
     * > **Invariant: `taken` is incremented on the TARGET here and the source is
     * > released only on settlement.** A move that is never paid for must leave
     * > the runner exactly where they were, holding the place they already paid
     * > for — releasing the source now would let somebody else take it while
     * > they are mid-checkout, and they would come back to a category that is
     * > full. The cost of this ordering is that an open move holds two places,
     * > which is what the hourly sweep exists to give back. Test it by COMPARING
     * > `taken` on both categories at THREE points (created / settled /
     * > expired); asserting the end state alone passes on a build that released
     * > the source early.
     *
     * An even or downward move is settled inside this call — there is nothing to
     * collect, and leaving it pending would have the runner staring at a payment
     * page for Rp 0. The fee breakdown is the CALLER's, resolved before this is
     * reached, because `PaymentFeeCalculator` takes a percentage of the amount
     * and `destinationFor()` branches on it.
     *
     * @throws PaymentException
     */
    public function start(
        RaceEntry $entry,
        RaceCategory $target,
        ?string $reason = null,
        string $paymentMethod = 'gateway',
        ?Carbon $deadline = null,
        ?string $paymentChannel = null,
        float $gatewayFee = 0.0,
        float $serviceFee = 0.0,
        float $gatewayTax = 0.0,
    ): RaceEntryTransfer {
        if (! $entry->canTransferCategory()) {
            throw new PaymentException($this->refusal($entry));
        }

        $quote = $this->quote($entry, $target);
        $owed = $quote['direction'] === RaceEntryTransfer::UP;

        $transfer = DB::transaction(function () use ($entry, $target, $quote, $reason, $owed, $paymentMethod, $deadline, $paymentChannel, $gatewayFee, $serviceFee, $gatewayTax) {
            // The reservation. See the invariant above for why only this side
            // moves now.
            $target->increment('taken');

            return RaceEntryTransfer::create([
                'race_entry_id' => $entry->id,
                'event_id' => $entry->event_id,
                'from_category_id' => $entry->race_category_id,
                'to_category_id' => $target->id,
                // The ENTRY's window, snapshotted beside its name for the same
                // reason `race_entries` does it: the period may be repriced or
                // deleted, and this row must keep saying what was compared.
                'race_period_id' => $entry->race_period_id,
                'period_name' => $quote['period_name'],
                'from_price' => $quote['from_price'],
                'to_price' => $quote['to_price'],
                'paid_before' => $quote['paid_before'],
                'direction' => $quote['direction'],
                'amount' => $quote['amount'],
                'status' => 'pending',
                // Only a top-up has a rail to travel on. A downward move is
                // settled below and its money comes back through
                // `refund_payouts`, which is manual by nature.
                'payment_method' => $owed ? $paymentMethod : 'gateway',
                'payment_channel' => $owed ? $paymentChannel : null,
                'gateway_fee' => $owed ? $gatewayFee : 0,
                'gateway_tax' => $owed ? $gatewayTax : 0,
                'service_fee' => $owed ? $serviceFee : 0,
                // A bill exists only for money being collected. An even move
                // and a downward one get no invoice, the call every rail makes
                // for a free order — a Rp 0 document claims a transaction that
                // never happened.
                'invoice_number' => $owed ? $this->number('invoice') : null,
                'payment_deadline_at' => $owed ? $deadline : null,
                'midtrans_order_id' => $owed ? $this->newOrderId() : null,
                'reason' => $reason,
            ]);
        });

        if (! $owed) {
            // Nothing to collect: apply it now. A downward move's difference is
            // handed back by settle() through RefundService.
            $this->settle($transfer->fresh());
        }

        return $transfer->fresh();
    }

    /**
     * Apply a settled move: the entry changes category, the source place goes
     * back, and the money lands.
     *
     * Idempotent — a re-delivered Midtrans webhook is a no-op, and the early
     * return is also what stops a second receipt being issued for one payment.
     *
     * > **Invariant: the bib is dropped and the QR is kept.** `bib_number`
     * > carries the source category's slug ("5K-003"), so a runner who moved to
     * > 10K would be holding a number that claims a category they are not in —
     * > and `BibService::assignNumbers()` never renumbers a row that already
     * > has one, so it has to be cleared for them to get a correct one. The
     * > printed sheet and the sent-at stamp go with it, because both describe
     * > the number that is gone. `qr_code` STAYS: it is the credential for the
     * > person, check-in reads the entry rather than the category, and minting a
     * > new one would silently invalidate the code already on their phone — the
     * > reason `ensureQrCode()` writes conditionally.
     */
    public function settle(RaceEntryTransfer $transfer, ?string $paymentType = null): void
    {
        if ($transfer->status === 'paid') {
            return;
        }

        $entry = $transfer->entry;

        DB::transaction(function () use ($transfer, $entry, $paymentType) {
            $transfer->update([
                'status' => 'paid',
                'paid_at' => Carbon::now(),
                // Written with `??` so the other doors into this method —
                // manual approval, an even move, dev's mock settle — cannot
                // blank what a real settlement stored. Same as every rail.
                'payment_type' => $paymentType ?? $transfer->payment_type,
                // `??` again: a re-delivered webhook must not issue a second
                // receipt. Only a top-up ever had a bill.
                'receipt_number' => $transfer->receipt_number
                    ?? ((float) $transfer->amount > 0 && $transfer->direction === RaceEntryTransfer::UP
                        ? $this->number('receipt')
                        : null),
            ]);

            // The place they came from, now genuinely free. Held until this
            // moment on purpose — see start().
            $transfer->fromCategory?->decrement('taken');

            $entry->update([
                'race_category_id' => $transfer->to_category_id,
                // See the invariant above: the number is category-stamped, the
                // QR is not.
                'bib_number' => null,
                'bib_png_key' => null,
                'bib_sent_at' => null,
            ]);

            $this->applyMoney($transfer->fresh(), $entry);
        });
    }

    /**
     * Where the difference goes.
     *
     * Split out so the two directions are read side by side rather than as a
     * branch buried in settle(): an upward move credits the organizer, a
     * downward one debits them and queues the transfer back. Manual money never
     * reaches the platform on either side, which is why the first arm asks.
     */
    protected function applyMoney(RaceEntryTransfer $transfer, RaceEntry $entry): void
    {
        if ($transfer->direction === RaceEntryTransfer::EVEN) {
            return;
        }

        if ($transfer->direction === RaceEntryTransfer::UP) {
            // A manual top-up went straight into the organizer's own bank
            // account, so crediting the wallet would have their balance claim
            // funds we are not holding. Same guard, same reason as every
            // markPaid() in this codebase.
            if (! $transfer->isOffPlatform()) {
                $this->wallet->creditRaceEntryTransfer($transfer->load('entry', 'event.organization'));
            }

            return;
        }

        // Downward: the runner is owed the difference. Through RefundService so
        // the debit and its payout row are written together inside one
        // transaction — the `refund_payouts` invariant, and the reason this does
        // not call the wallet itself.
        $this->refunds->refundRaceEntryTransfer($transfer, $entry);
    }

    /**
     * Reopen payment for a top-up that was started and never finished.
     *
     * The runner who closed the Midtrans tab, or backed out of it, has an open
     * move on their proof page saying money is owed and — before this — nowhere
     * to pay it. The place was already taken in the target category and the
     * hourly sweep only reaches the manual rail, so without this door that
     * place sits held until a human notices.
     *
     * > **A FRESH Midtrans order id, keeping the invoice number.** Snap tokens
     * > expire after about 24 hours, so reusing the id hands back a token that
     * > cannot be paid; reissuing the invoice number instead would put a second
     * > bill in front of somebody for one debt. Exactly the split
     * > `EventPlanOrderService::pay()` makes, including what happens to the old
     * > id: it is abandoned and expires on Midtrans' side, and a late webhook
     * > for it finds no row and 404s — harmless, because the row it would have
     * > settled is the one being paid for here.
     *
     * Only an upward move on the gateway rail can be reopened. A manual one is
     * paid by uploading proof (`submitProof()`), and an even or downward move
     * was settled the moment it was created.
     *
     * @throws PaymentException
     */
    public function reopen(RaceEntryTransfer $transfer): RaceEntryTransfer
    {
        if ($transfer->status !== 'pending') {
            throw new PaymentException('Pindah kategori ini sudah tidak menunggu pembayaran.');
        }

        if ($transfer->direction !== RaceEntryTransfer::UP || (float) $transfer->amount <= 0) {
            throw new PaymentException('Pindah kategori ini tidak perlu dibayar.');
        }

        if ($transfer->isManual()) {
            throw new PaymentException('Pindah kategori ini dibayar lewat transfer manual. Unggah bukti transfernya.');
        }

        $transfer->update([
            'midtrans_order_id' => $this->newOrderId(),
            // Cleared so a stale token cannot be handed back if minting the new
            // one fails: an expired token in the client's hand looks exactly
            // like a working one until the runner clicks it.
            'midtrans_token' => null,
        ]);

        return $transfer->fresh();
    }

    /**
     * Give up on a move the runner started and no longer wants.
     *
     * Their own door into `cancel()`, which is what releases the place held in
     * the target category. Without it the only thing that frees it is the
     * hourly sweep — and that reaches the manual rail only, so a runner who
     * backed out of a gateway payment would hold two places until the organizer
     * noticed. The entry is untouched: it never left its original category.
     *
     * @throws PaymentException
     */
    public function abandon(RaceEntryTransfer $transfer): void
    {
        if ($transfer->status !== 'pending') {
            throw new PaymentException('Pindah kategori ini sudah tidak bisa dibatalkan.');
        }

        $this->cancel($transfer);
    }

    /**
     * The runner uploads their transfer receipt for the top-up.
     *
     * @throws PaymentException
     */
    public function submitProof(RaceEntryTransfer $transfer, string $proofUrl): void
    {
        if (! $transfer->isManual()) {
            throw new PaymentException('Pindah kategori ini tidak dibayar lewat transfer manual.');
        }

        if ($transfer->isSettled()) {
            throw new PaymentException('Pindah kategori ini sudah dibayar.');
        }

        $transfer->attachProof($proofUrl);
    }

    /**
     * Accept a manual top-up. settle() is what keeps this off the wallet when
     * the money went to the organizer's own account.
     *
     * @throws PaymentException
     */
    public function approveProof(RaceEntryTransfer $transfer, User $admin): void
    {
        if (! $transfer->isAwaitingVerification()) {
            throw new PaymentException('Tidak ada bukti pembayaran yang menunggu verifikasi.');
        }

        $transfer->markVerified($admin);
        $this->settle($transfer->fresh());
    }

    /**
     * @throws PaymentException
     */
    public function rejectProof(RaceEntryTransfer $transfer, string $reason, Carbon $deadline): void
    {
        if (! $transfer->isAwaitingVerification()) {
            throw new PaymentException('Tidak ada bukti pembayaran yang menunggu verifikasi.');
        }

        $transfer->rejectProof($reason, $deadline);
    }

    /**
     * Abandon an unpaid move and give the target place back.
     *
     * The guard is the same by-value list every other rail carries, and for the
     * same reason: a settled, cancelled or expired move has already released
     * (or must never release) a place, and running this twice would hand back
     * one nobody was holding. The ENTRY is untouched — it never left its
     * original category, which is exactly what start()'s ordering guarantees.
     */
    public function cancel(RaceEntryTransfer $transfer, string $status = 'cancelled'): void
    {
        if (in_array($transfer->status, ['paid', 'cancelled', 'expired', 'refunded'], true)) {
            return;
        }

        DB::transaction(function () use ($transfer, $status) {
            $transfer->update(['status' => $status]);
            $transfer->toCategory?->decrement('taken');
        });
    }

    /**
     * Why this entry cannot be moved, in the runner's words.
     *
     * One sentence per cause, because the runner can act on the difference: an
     * unpaid entry is cancelled and re-entered, a checked-in one has already
     * run, an open move is simply the one they started, and a missing period is
     * the organizer's to fix.
     */
    protected function refusal(RaceEntry $entry): string
    {
        return match (true) {
            $entry->status !== 'paid' => 'Hanya pendaftaran lunas yang bisa pindah kategori. Batalkan pendaftaran ini dan daftar ulang di kategori yang kamu mau.',
            $entry->checked_in_at !== null => 'Kamu sudah check-in di lokasi, kategorinya tidak bisa dipindah lagi.',
            $entry->hasOpenTransfer() => 'Kamu sudah mengajukan pindah kategori. Selesaikan atau batalkan yang itu dulu.',
            $entry->hasOpenRefundRequest() => 'Pengajuan refund kamu masih menunggu keputusan penyelenggara.',
            $entry->race_period_id === null => 'Periode pendaftaran entry ini sudah tidak ada, jadi harga pindahnya tidak bisa dihitung. Hubungi penyelenggara.',
            default => 'Pendaftaran ini tidak bisa pindah kategori.',
        };
    }

    /** @param  'invoice'|'receipt'  $kind */
    protected function number(string $kind): string
    {
        return $this->numbers->next(
            RaceEntryTransfer::class,
            "{$kind}_number",
            config("billing.transfer_{$kind}_prefix"),
        );
    }

    /**
     * A fresh Midtrans order id for a top-up.
     *
     * `RXF-` is load-bearing: MidtransWebhookController routes on the prefix,
     * and its `default` arm is the plan-order path — an id minted without a
     * recognised prefix settles nothing and answers Midtrans with a 404, which
     * is retried a few times and then abandoned with nothing logged. That has
     * already happened once, to the `RUN-` arm.
     *
     * Deliberately not `RUN-`: that prefix resolves against `race_entries`, so
     * a top-up carrying it would have the webhook look up an entry by an order
     * id only this table holds.
     */
    public function newOrderId(): string
    {
        return 'RXF-'.Str::upper(Str::random(10));
    }
}
