<?php

namespace App\Services;

use App\Exceptions\WalletException;
use App\Models\RaceEntry;
use App\Models\Team;
use App\Models\TicketOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Refunds. Voids the order and reverses the organizer's credit.
 *
 * This does NOT return money to the buyer — the platform holds it in its own
 * Midtrans account, so the transfer back is a separate manual step. What the
 * admin UI used to only *say*, `refund_payouts` now records: every reversal
 * that actually moved money queues a transfer debt, so the manual half of a
 * refund stops being a thing somebody has to remember.
 *
 * > **Invariant: a refund and its payout row are written together.**
 * > `RefundPayoutService::record()` is called INSIDE the same transaction as
 * > the reversal and reads that reversal's own `amount`. Called afterwards it
 * > would survive a rolled-back refund as a debt for money still sitting in
 * > the organizer's balance; called with a recomputed figure it would drift
 * > from what left the wallet. The null return from `reverse()` is load-bearing
 * > — see `record()` for the three cases where nothing is owed.
 */
class RefundService
{
    public function __construct(
        protected TicketService $tickets,
        protected WalletService $wallet,
        protected RefundPayoutService $payouts,
    ) {}

    public function refundTicketOrder(TicketOrder $order, ?User $actor = null, ?string $reason = null): void
    {
        if ($order->status !== 'paid') {
            throw new WalletException('Hanya pesanan lunas yang bisa direfund.');
        }

        if ($order->tickets()->where('is_used', true)->exists()) {
            throw new WalletException('Ada tiket yang sudah dipakai check-in, pesanan ini tidak bisa direfund.');
        }

        DB::transaction(function () use ($order, $actor, $reason) {
            $this->tickets->refund($order);
            $reversal = $this->reverse('ticket_order', $order->id, 'ticket_sale', $actor, $reason);

            $this->payouts->record(
                'ticket_order',
                $order->id,
                $order->event->organization,
                $order->event_id,
                $reversal,
                $order->buyer_name,
                $reason,
            );
        });
    }

    public function refundTeam(Team $team, ?User $actor = null, ?string $reason = null): void
    {
        if ($team->payment_status !== 'paid') {
            throw new WalletException('Hanya pendaftaran lunas yang bisa direfund.');
        }

        if ((float) $team->payment_amount <= 0) {
            throw new WalletException('Pendaftaran gratis tidak bisa direfund.');
        }

        DB::transaction(function () use ($team, $actor, $reason) {
            $team->update(['payment_status' => 'refunded']);
            $reversal = $this->reverse('team', $team->id, 'registration_fee', $actor, $reason);

            $this->payouts->record(
                'team',
                $team->id,
                $team->event->organization,
                $team->event_id,
                $reversal,
                $team->name,
                $reason,
            );
        });
    }

    /**
     * Refund a fun run entry.
     *
     * Its own arm rather than a branch inside one of the two above, because the
     * ledger row it has to find is keyed on its own `source_type`/`category`
     * pair: without this method a refunded entry voids the entry and leaves the
     * organizer credited for money that went back, which `wallet:audit` cannot
     * see — the ledger stays internally consistent, it is just one row richer
     * than the truth.
     *
     * Phase two will add a used-check here, the counterpart of the
     * already-scanned guard on a ticket order: once a runner has been checked
     * in at the start line there is nothing left to refund. There is no such
     * check now because nothing writes `checked_in_at` yet, and a guard reading
     * a column no path fills would be dead code pretending to be a rule.
     *
     * @throws WalletException
     */
    public function refundRaceEntry(RaceEntry $entry, ?User $actor = null, ?string $reason = null): void
    {
        if ($entry->status !== 'paid') {
            throw new WalletException('Hanya entry lunas yang bisa direfund.');
        }

        if ((float) $entry->price <= 0) {
            throw new WalletException('Entry gratis tidak bisa direfund.');
        }

        DB::transaction(function () use ($entry, $actor, $reason) {
            $entry->update(['status' => 'refunded']);
            // The category released here is the same string creditRaceEntry()
            // wrote; they are spelled apart on purpose only in the sense that
            // nothing derives one from the other — a typo in either leaves the
            // credit standing and the refund silent.
            $reversal = $this->reverse('race_entry', $entry->id, 'race_entry', $actor, $reason);

            // The place goes back on offer, the mirror of TicketService::release().
            $entry->category()->decrement('taken');

            $this->payouts->record(
                'race_entry',
                $entry->id,
                $entry->event->organization,
                $entry->event_id,
                $reversal,
                $entry->full_name,
                $reason,
            );
        });
    }

    /**
     * Pull the credit back, and hand the caller the row that moved.
     *
     * Returning the reversal is what lets `refund_payouts.amount` be READ
     * rather than recomputed: this is the only place that knows both which
     * ledger row belonged to the order and what reversing it actually cost.
     * A null return means no money moved — a free order, a manual transfer that
     * never passed through the platform, or a credit still `pending`, which
     * `reverseCredit()` cancels in place without a debit.
     */
    protected function reverse(string $sourceType, string $sourceId, string $category, ?User $actor, ?string $reason): ?WalletTransaction
    {
        $credit = WalletTransaction::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('category', $category)
            ->first();

        // No credit exists for a free order, or one refunded before the wallet
        // shipped — voiding the order is still the right outcome.
        if (! $credit) {
            return null;
        }

        // Null again when the credit was still `pending`: it is cancelled in
        // place with no debit written, because it was never withdrawable. The
        // organizer is not owed a transfer for money they could not have taken.
        return $this->wallet->reverseCredit($credit, $actor, $reason);
    }
}
