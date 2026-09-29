<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organization's balance of money the platform collected on its behalf.
 * Balances are denormalized so a withdrawal can lock and check them in one
 * row; `wallet_transactions` remains the source of truth (see wallet:audit).
 */
class Wallet extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'balance_available',
        'balance_pending',
        'total_earned',
        'total_withdrawn',
    ];

    protected function casts(): array
    {
        return [
            'balance_available' => 'decimal:2',
            'balance_pending' => 'decimal:2',
            'total_earned' => 'decimal:2',
            'total_withdrawn' => 'decimal:2',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    /**
     * Money already debited from `balance_available` but not yet transferred —
     * derived from the open withdrawals rather than stored, so it cannot drift.
     */
    public function onHold(): float
    {
        return (float) $this->withdrawals()
            ->whereIn('status', ['pending', 'processing'])
            ->sum('total_debit');
    }

    /**
     * Net available money that came from events which are over — the *stock* the
     * withdrawal minimum is waived against.
     *
     * `finished` and `cancelled` both count: neither can earn again, and a
     * cancelled event is only one step from where it stood (see
     * Event::nextStatuses()), so excluding it would strand the money behind an
     * un-cancel it should never need.
     *
     * Refund debits carry their credit's `event_id` (see
     * WalletService::reverseCredit()), so refunds net themselves out here with no
     * second rule. Withdrawals, reversals and adjustments carry none, which is
     * why they are absent by construction — an admin's mistyped correction must
     * not become instantly withdrawable, and consumption is tracked in
     * `withdrawals.exempt_consumed` instead of inferred from this sum.
     *
     * A join rather than `event_id IN (subquery)`: `events.status` is unindexed,
     * so the subquery form seq-scans every event on the platform and costs grow
     * with the platform rather than with this wallet. This also runs inside the
     * FOR UPDATE window in WithdrawalService::request(), so it is one round trip.
     */
    public function exemptSourceBalance(): float
    {
        $rows = $this->transactions()
            ->join('events', 'events.id', '=', 'wallet_transactions.event_id')
            ->where('wallet_transactions.status', 'available')
            ->whereIn('events.status', ['finished', 'cancelled'])
            ->selectRaw('wallet_transactions.type as type, SUM(wallet_transactions.amount) as total')
            ->groupBy('wallet_transactions.type')
            ->pluck('total', 'type');

        return round((float) ($rows['credit'] ?? 0) - (float) ($rows['debit'] ?? 0), 2);
    }

    /**
     * How much of `balance_available` may be withdrawn below the minimum.
     *
     * Stock minus recorded draws, never stock minus the withdrawal flow: payout
     * debits carry no `event_id`, so an ordinary above-minimum payout out of a
     * live event would appear to drain this and kill the waiver forever. Every
     * payout writes down what it actually took (`exempt_consumed`), and only the
     * payouts still holding their money are counted — a rejected or cancelled one
     * drops out via its own status, because reverseWithdrawal() has already put
     * the money back.
     *
     * Floored at 0 for wallets driven negative by a refund. The
     * `balance_available` ceiling is a guard rather than load-bearing: today no
     * write path can produce event-tagged debits exceeding their credits, and it
     * is here so a third one cannot quietly hand out money that is not there.
     */
    public function minimumWaivedBalance(): float
    {
        $consumed = (float) $this->withdrawals()
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->sum('exempt_consumed');

        $waived = $this->exemptSourceBalance() - $consumed;

        return round(max(0.0, min($waived, (float) $this->balance_available)), 2);
    }
}
