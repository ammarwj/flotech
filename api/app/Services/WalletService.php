<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceEntry;
use App\Models\RaceEntryTransfer;
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
 * next 01:00 in the organizer's zone has passed — an overnight window in which a
 * refund can cancel the credit outright instead of clawing money back.
 *
 * The clock is the *only* thing that releases funds. It used to also happen when
 * an event finished, and two readers of one rule disagree sooner or later; what
 * an ended event does now is waive the withdrawal minimum for its own money
 * (see Wallet::minimumWaivedBalance()), not move anything.
 *
 * Every mutation goes through record(), which locks the wallet row and relies
 * on a unique index over (source_type, source_id, category) so a re-delivered
 * Midtrans webhook can never credit the same order twice.
 */
class WalletService
{
    /**
     * Credit categories that count towards `wallets.total_earned`.
     *
     * > **Invarian: daftar ini wajib tumbuh bersama setiap rail pendapatan
     * > baru.** Rail keempat yang lupa didaftarkan di sini **tetap mengkredit
     * > saldo dengan benar** — cabang `increment($column)` di atasnya tidak
     * > membaca `category` sama sekali — lalu diam-diam kurang menghitung
     * > `total_earned`, dan `wallet:audit` tetap hijau karena ia memeriksa
     * > `balance_* = Σ ±amount` dan tidak pernah menyentuh kolom ini. Ujilah
     * > dengan **membandingkan** `total_earned` DAN `balance_pending` sesudah
     * > satu kredit; pasangan yang lebih kuat: satu kredit tiket + satu kredit
     * > entry di event yang sama, assert `total_earned` = jumlah keduanya.
     *
     * An adjustment or a reversal is deliberately absent: `total_earned` is
     * what the organizer has earned, not what their balance has done.
     *
     * @var list<string>
     */
    private const EARNING_CATEGORIES = ['ticket_sale', 'registration_fee', 'race_entry', 'race_entry_transfer'];

    public function forOrganization(Organization $org): Wallet
    {
        return Wallet::firstOrCreate(['organization_id' => $org->id]);
    }

    /**
     * When a credit written now becomes withdrawable: the next 01:00 in the
     * organizer's zone, plus any extra cooling days.
     *
     * Not derived from `events.end_date` any more. A league runs for months, and
     * its first week's ticket money is spendable cash rather than a deposit.
     *
     * The boundary has to be computed *in* `wallet.timezone`: the app runs in
     * UTC, so naive arithmetic lands the cut-off seven hours off — the same trap
     * that used to release an event's funds at 07:00 WIB on its final day.
     *
     * Literal, with no minimum-one-night rule: a credit at 00:30 WIB clears at
     * 01:00 the same morning, one at 10:00 WIB clears at 01:00 the next.
     */
    public function availableAtFor(?Carbon $now = null): Carbon
    {
        $local = ($now ? $now->copy() : Carbon::now())->setTimezone(config('wallet.timezone'));

        $boundary = $local->copy()->setTime(1, 0, 0);

        if ($boundary->lte($local)) {
            $boundary->addDay();
        }

        return $boundary->addDays(PlatformSettings::holdDays())->utc();
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
     * Credit an organizer for a paid fun run entry.
     *
     * Beside the two above rather than folded into either: an entry is neither a
     * seat nor a squad, and `EARNING_CATEGORIES` below is what has to know it
     * exists.
     */
    public function creditRaceEntry(RaceEntry $entry): ?WalletTransaction
    {
        $event = $entry->event;
        $gross = (float) $entry->price;
        $fee = (float) $entry->platform_fee;

        return $this->credit(
            $event,
            'race_entry',
            $gross,
            $fee,
            'race_entry',
            $entry->id,
            "Entry {$entry->full_name} — {$event->name}",
        );
    }

    /**
     * Credit an organizer for the difference a runner paid to move up a
     * category.
     *
     * Its own category rather than a second `race_entry` row, and the unique
     * index over `(source_type, source_id, category)` is why it has to be: a
     * transfer is a different source row, so the pair would not collide — but
     * the ledger would then carry two rows a reader cannot tell apart, and
     * `refundRaceEntry()` looks its row up by exactly that triple. A move to a
     * cheaper category is NOT this method's business: that reverses part of the
     * original credit through `RefundService`, because writing a negative
     * credit here is how a balance starts running backwards.
     */
    public function creditRaceEntryTransfer(RaceEntryTransfer $transfer): ?WalletTransaction
    {
        $entry = $transfer->entry;
        $event = $transfer->event;

        return $this->credit(
            $event,
            'race_entry_transfer',
            (float) $transfer->amount,
            // Nothing is taken off a top-up: the entry it tops up was already
            // charged whatever the event's fee arrangement is, and a second cut
            // on the difference would make moving cost more than entering the
            // target category outright.
            0.0,
            'race_entry_transfer',
            $transfer->id,
            "Pindah kategori {$entry->full_name} — {$event->name}",
        );
    }

    /**
     * Sweep every pending credit whose boundary has passed. Idempotent: a second
     * run moves nothing.
     *
     * One condition, deliberately. There is no "the event finished" arm and no
     * exception for cancelled events: the event's status decides whether its
     * money is exempt from the withdrawal minimum, never whether it is released.
     * Cancelling pauses nothing here — a refund is what takes money back.
     */
    public function releaseDue(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        $query = WalletTransaction::where('status', 'pending')
            ->where('available_at', '<=', $now);

        return $this->releaseTransactions($query, $now);
    }

    /**
     * Take back the difference a runner is owed after moving to a CHEAPER
     * category.
     *
     * Not `reverseCredit()`, and the difference is the whole reason this method
     * exists: that one undoes a credit ROW, and here the row must survive
     * untouched. The entry is still live, the organizer still keeps the price of
     * the category the runner moved into, and `wallet:audit` reconstructs the
     * balances from whole rows — editing one is how a ledger stops reconciling.
     * After a chain (5K→21K→10K) there are several credit rows feeding one
     * place anyway, so "which row does this come out of" has no honest answer;
     * what is owed is a number, so a number is what gets debited.
     *
     * > **Written as `available`, always, with no pending arm.** That looks
     * > wrong beside `reverseCredit()`, which cancels a pending credit in place
     * > — but that branch exists because it undoes the very row it is reading,
     * > and there is no such row here. Splitting the debit across the two
     * > buckets by guessing which credit it belongs to would need a second rule
     * > nothing else in the ledger has, and the per-bucket identity
     * > (`balance_* = Σ ±amount WHERE status = …`) holds either way: an
     * > available debit sums into available. The arithmetic also lands right
     * > over time — a downgrade before release leaves available at −100 and
     * > pending at +200, and the 01:00 sweep releases the credit into it for a
     * > net 100. A negative balance is an accepted state with a designed
     * > consequence (it locks the next withdrawal), already written for a
     * > refund of money the organizer may have withdrawn.
     *
     * Returns the debit so `RefundPayoutService::record()` can READ the amount
     * that actually left the wallet instead of recomputing it — the
     * `refund_payouts` invariant. Null when there was nothing to take back,
     * which is a manual-rail or free entry: that money never reached the
     * platform, so the organizer owes the runner directly and there is no
     * platform transfer to queue.
     */
    public function debitRaceEntryTransfer(RaceEntryTransfer $transfer, ?User $actor = null, ?string $reason = null): ?WalletTransaction
    {
        $entry = $transfer->entry;
        $event = $transfer->event;
        $amount = round((float) $transfer->amount, 2);

        if ($amount <= 0) {
            return null;
        }

        // What the platform is ACTUALLY holding for this runner, and the debit
        // is capped at it.
        //
        // > **Both rails count, and asking only about the entry's own credit is
        // > a bug this test caught.** A registration paid by manual transfer
        // > has no `race_entry` credit — that money went to the organizer's own
        // > bank — but a top-up paid on the GATEWAY rail afterwards really did
        // > land in the platform's account and really was credited. Reading
        // > only the entry would have the one runner whose money we hold be the
        // > one nobody queues a transfer for. The reverse pairing is just as
        // > real: a gateway entry topped up by hand.
        //
        // Capped rather than all-or-nothing, for the same reason: the platform
        // cannot return more than it took. A manual entry that topped up
        // 100k on the gateway and then drops 150k owes 100k back here, and the
        // remaining 50k is between the runner and the organizer — exactly as it
        // was for the manual half of what they paid in the first place.
        $held = $this->heldForEntry($entry);

        if ($held <= 0) {
            return null;
        }

        $amount = min($amount, $held);

        $wallet = $this->forOrganization($event->organization);

        return $this->record($wallet, [
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
            'type' => 'debit',
            // The same category every other money-going-back row carries, so
            // the ledger filter that finds refunds finds this one too.
            'category' => 'refund',
            'status' => 'available',
            'amount' => $amount,
            'gross_amount' => $amount,
            'fee_amount' => 0,
            // Keyed to the TRANSFER, not the entry: the entry's own credit is
            // still standing, and `(source_type, source_id, category)` is what
            // makes a replayed downgrade a no-op.
            'source_type' => 'race_entry_transfer',
            'source_id' => $transfer->id,
            'created_by' => $actor?->id,
            'description' => $reason
                ? "Selisih pindah kategori {$entry->full_name} — {$event->name} ({$reason})"
                : "Selisih pindah kategori {$entry->full_name} — {$event->name}",
        ]);
    }

    /**
     * What the platform is still holding for one runner: every live credit
     * raised by their entry and by its category moves, minus whatever has
     * already been handed back.
     *
     * One query over `source_id`, which is what makes it rail-agnostic: a
     * registration paid by hand and topped up through the gateway contributes
     * only its top-up, and the reverse pairing contributes only its entry. A
     * boolean "was anything credited" cannot express either, which is how the
     * first version of `debitRaceEntryTransfer()` came to skip the one runner
     * whose money we actually held.
     *
     * `cancelled` rows are excluded because they never counted towards a
     * balance; earlier downgrade debits ARE subtracted, so two moves down
     * cannot return the same money twice.
     */
    protected function heldForEntry(RaceEntry $entry): float
    {
        $sourceIds = $entry->transfers()->pluck('id')->push($entry->id)->all();

        $rows = WalletTransaction::whereIn('source_id', $sourceIds)
            ->whereIn('source_type', ['race_entry', 'race_entry_transfer'])
            ->where('status', '!=', 'cancelled')
            ->get(['type', 'amount']);

        return round(
            $rows->sum(fn (WalletTransaction $row) => $row->signedAmount()),
            2,
        );
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
            'available_at' => $this->availableAtFor(),
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

            if ($tx->type === 'credit' && in_array($tx->category, self::EARNING_CATEGORIES, true)) {
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
     * wallet. Rows are re-checked under the lock, so two overlapping sweeps
     * cannot double-release the same row.
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
