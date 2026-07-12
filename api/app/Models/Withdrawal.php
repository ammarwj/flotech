<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payout request. Funds leave `balance_available` the moment it is created;
 * a super admin then transfers them by hand and records the proof. Rejecting
 * (or the organizer cancelling) writes a reversal back into the wallet.
 *
 * Not to be confused with a *team* withdrawing from an event (teams.status).
 */
class Withdrawal extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'wallet_id',
        'bank_account_id',
        'reference',
        'amount',
        'admin_fee',
        'total_debit',
        'minimum_at_request',
        'status',
        'bank_name',
        'bank_code',
        'account_number',
        'account_holder',
        'note',
        'proof_url',
        'transfer_reference',
        'admin_note',
        'requested_by',
        'processed_by',
        'processed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'admin_fee' => 'decimal:2',
            'total_debit' => 'decimal:2',
            'minimum_at_request' => 'decimal:2',
            'processed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /** Still holding funds — blocks a second request and can still be reversed. */
    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'processing'], true);
    }
}
