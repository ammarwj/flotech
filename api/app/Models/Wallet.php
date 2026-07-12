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
}
