<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TicketOrder;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only thing allowed to move money in an organizer's wallet.
 *
 * Buyers pay the platform's Midtrans account, so the organizer's net share
 * (amount − platform_fee) is credited here as *pending* and released once the
 * event is over — a window in which a refund can cancel the credit outright
 * instead of clawing money back.
 *
 * Every mutation goes through record(), which locks the wallet row and relies
 * on a unique index over (source_type, source_id, category) so a re-delivered
 * Midtrans webhook can never credit the same order twice.
 */
class WalletService
{
    public function forOrganization(Organization $org): Wallet
    {
        return Wallet::firstOrCreate(['organization_id' => $org->id]);
    }

    /**
     * When an event's funds become withdrawable.
     *
     * `events.end_date` is a DATE and the app runs in UTC, so a naive
     * `end_date <= now()` would release money at 07:00 WIB *on the final day*,
     * while the event is still being played. The event ends at the end of that
     * day in the organizer's zone.
     */
    public function availableAtFor(Event $event): Carbon
    {
        return Carbon::parse(
            $event->end_date->toDateString().' 23:59:59',
            config('wallet.timezone'),
        )->utc()->addDays(PlatformSettings::holdDays());
    }

    /**
     * Credit an organizer for a paid ticket order. Returns null when there is
     * nothing to credit (free tickets), keeping their ledger clean.
     */
    public function creditTicketOrder(TicketOrder $order): ?WalletTransaction
    {
        $event = $order->event;
        $gross = (float) $order->total_price;
        $fee = (float) $order->platform_fee;

        return $this->credit(
            $event,
            'ticket_sale',
            $gross,
            $fee,
            'ticket_order',
            $order->id,
            "Penjualan {$order->quantity} tiket — {$event->name}",
        );
    }

    /** Credit an organizer for a paid team registration fee. */
    public function creditRegistration(Team $team): ?WalletTransaction
    {
        $event = $team->event;
        $gross = (float) $team->payment_amount;
        $fee = (float) $team->platform_fee;

        return $this->credit(
            $event,
            'registration_fee',
            $gross,
            $fee,
            'team',
            $team->id,
            "Biaya pendaftaran {$team->name} — {$event->name}",
        );
    }

    /**
     * Move an event's pending funds into the available balance. Ignores
     * `available_at` — the caller has established the event is over.
     */
    public function releaseEvent(Event $event): int
    {
        if ($event->status === 'cancelled') {
            return 0;
        }

        return $this->releaseTransactions(
            WalletTransaction::where('event_id', $event->id)->where('status', 'pending')
        );
    }

    /**
     * Sweep every pending credit whose event has finished (explicitly, or by
     * its end date passing). Idempotent: a second run moves nothing.
     */
    public function releaseDue(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        $query = WalletTransaction::where('status', 'pending')
            ->whereHas('event', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->where(function ($q) use ($now) {
                $q->whereHas('event', fn ($e) => $e->where('status', 'finished'))
                    ->orWhere('available_at', '<=', $now);
            });

        return $this->releaseTransactions($query, $now);
    }

    /**
     * Undo a credit. A still-pending credit is cancelled outright (no debit, no
     * negative balance); a released one is reversed with a debit, which *can*
     * push the balance negative — intentionally, since the organizer may have
     * already withdrawn the money.
     */
    public function reverseCredit(WalletTransaction $credit, ?User $actor = null, ?string $reason = null): ?WalletTransaction
    {
        if ($credit->type !== 'credit' || $credit->status === 'cancelled') {
            return null;
        }

        $wallet = Wallet::findOrFail($credit->wallet_id);

        if ($credit->status === 'pending') {
            DB::transaction(function () use ($credit, $wallet, $reason) {
                $locked = Wallet::whereKey($wallet->id)->lockForUpdate()->first();
                $fresh = WalletTransaction::whereKey($credit->id)->lockForUpdate()->first();

                if ($fresh->status !== 'pending') {
                    return; // released or cancelled while we waited
                }

                $fresh->update([
                    'status' => 'cancelled',
                    'description' => $reason ? "{$fresh->description} (dibatalkan: {$reason})" : $fresh->description,
                ]);

                $locked->decrement('balance_pending', $fresh->amount);
                $locked->decrement('total_earned', $fresh->amount);
            });

            return null;
        }

        return $this->record($wallet, [
            'organization_id' => $credit->organization_id,
            'event_id' => $credit->event_id,
            'type' => 'debit',
            'category' => 'refund',
            'status' => 'available',
            'amount' => $credit->amount,
            'gross_amount' => $credit->gross_amount,
            'fee_amount' => 0,
            'source_type' => $credit->source_type,
            'source_id' => $credit->source_id,
            'created_by' => $actor?->id,
            'description' => $reason ? "Refund: {$reason}" : 'Refund',
        ]);
    }

    /** Hold a payout's funds the moment it is requested. */
    public function holdWithdrawal(Wallet $wallet, Withdrawal $withdrawal): WalletTransaction
    {
        return $this->record($wallet, [
            'organization_id' => $withdrawal->organization_id,
            'type' => 'debit',
            'category' => 'withdrawal',
            'status' => 'available',
            'amount' => $withdrawal->total_debit,
            'gross_amount' => $withdrawal->amount,
            'fee_amount' => $withdrawal->admin_fee,
            'source_type' => 'withdrawal',
            'source_id' => $withdrawal->id,
            'created_by' => $withdrawal->requested_by,
            'description' => "Penarikan dana {$withdrawal->reference}",
        ]);
    }

    /** Return a rejected or cancelled payout's funds to the wallet. */
    public function reverseWithdrawal(Withdrawal $withdrawal): ?WalletTransaction
    {
        return $this->record(Wallet::findOrFail($withdrawal->wallet_id), [
            'organization_id' => $withdrawal->organization_id,
            'type' => 'credit',
            'category' => 'withdrawal_reversal',
            'status' => 'available',
            'amount' => $withdrawal->total_debit,
            'gross_amount' => $withdrawal->amount,
            'fee_amount' => $withdrawal->admin_fee,
            'source_type' => 'withdrawal',
            'source_id' => $withdrawal->id,
            'description' => "Pengembalian dana penarikan {$withdrawal->reference}",
        ]);
    }

    /**
     * Record a completed payout. Writes no ledger row — the debit was already
     * made when the request was created — it only advances the lifetime total.
     */
    public function settleWithdrawal(Withdrawal $withdrawal): void
    {
        Wallet::whereKey($withdrawal->wallet_id)->increment('total_withdrawn', $withdrawal->amount);
    }

    /** A manual super-admin correction. Not idempotent — it has no source. */
    public function adjust(Wallet $wallet, float $amount, string $description, ?User $actor = null): WalletTransaction
    {
        return $this->record($wallet, [
            'organization_id' => $wallet->organization_id,
            'type' => $amount >= 0 ? 'credit' : 'debit',
            'category' => 'adjustment',
            'status' => 'available',
            'amount' => abs($amount),
            'created_by' => $actor?->id,
            'description' => $description,
        ]);
    }

    /**
     * Shared credit path for both income sources. The net (gross − platform
     * fee) is what the organizer earns; a non-positive net writes nothing.
     */
    protected function credit(
        Event $event,
        string $category,
        float $gross,
        float $fee,
        string $sourceType,
        string $sourceId,
        string $description,
    ): ?WalletTransaction {
        $net = round($gross - $fee, 2);

        if ($net <= 0) {
            return null;
        }

        $wallet = $this->forOrganization($event->organization);

        return $this->record($wallet, [
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
            'type' => 'credit',
            'category' => $category,
            'status' => 'pending',
            'amount' => $net,
            'gross_amount' => $gross,
            'fee_amount' => $fee,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'available_at' => $this->availableAtFor($event),
            'description' => $description,
        ]);
    }

    /**
     * Write one ledger row and apply it to the wallet balances, under a lock.
     *
     * Idempotent by source: the pre-check avoids the common re-delivery, and
     * the unique index catches the racing one. Either way the balance moves at
     * most once.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function record(Wallet $wallet, array $attrs): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $attrs) {
            if ($existing = $this->findBySource($attrs)) {
                return $existing;
            }

            $locked = Wallet::whereKey($wallet->id)->lockForUpdate()->first();

            try {
                $tx = $locked->transactions()->create($attrs);
            } catch (QueryException $e) {
                // Lost a race to the unique index: the movement already happened.
                if ($existing = $this->findBySource($attrs)) {
                    return $existing;
                }
                throw $e;
            }

            $column = $tx->status === 'pending' ? 'balance_pending' : 'balance_available';

            $tx->type === 'credit'
                ? $locked->increment($column, $tx->amount)
                : $locked->decrement($column, $tx->amount);

            if ($tx->type === 'credit' && in_array($tx->category, ['ticket_sale', 'registration_fee'], true)) {
                $locked->increment('total_earned', $tx->amount);
            }

            return $tx;
        });
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function findBySource(array $attrs): ?WalletTransaction
    {
        if (empty($attrs['source_type']) || empty($attrs['source_id'])) {
            return null;
        }

        return WalletTransaction::where('source_type', $attrs['source_type'])
            ->where('source_id', $attrs['source_id'])
            ->where('category', $attrs['category'])
            ->first();
    }

    /**
     * Flip a set of pending rows to available and move the balances, wallet by
     * wallet. Rows are re-checked under the lock, so concurrent runs (the
     * hourly command racing the "event finished" job) cannot double-release.
     *
     * @param  Builder<WalletTransaction>  $query
     */
    protected function releaseTransactions($query, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $released = 0;

        $walletIds = (clone $query)->distinct()->pluck('wallet_id');

        foreach ($walletIds as $walletId) {
            $released += DB::transaction(function () use ($query, $walletId, $now) {
                $wallet = Wallet::whereKey($walletId)->lockForUpdate()->first();
                if (! $wallet) {
                    return 0;
                }

                $rows = (clone $query)->where('wallet_id', $walletId)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->get();

                if ($rows->isEmpty()) {
                    return 0;
                }

                $total = 0.0;
                foreach ($rows as $row) {
                    $row->update(['status' => 'available', 'released_at' => $now]);
                    $total += $row->signedAmount();
                }

                $wallet->decrement('balance_pending', $total);
                $wallet->increment('balance_available', $total);

                return $rows->count();
            });
        }

        return $released;
    }
}
