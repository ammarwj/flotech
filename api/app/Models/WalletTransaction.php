<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable movement in an organization's wallet.
 *
 * `amount` is always positive; `type` gives it a sign. `status` says which
 * balance it sits in — pending funds are held until the event finishes, and a
 * refund of a still-pending credit flips it to `cancelled` instead of writing
 * an opposing debit.
 *
 * The ledger identity, asserted by wallet:audit:
 *   balance_available = SUM(±amount WHERE status = 'available')
 *   balance_pending   = SUM(±amount WHERE status = 'pending')
 */
class WalletTransaction extends Model
{
    use HasUuids;

    protected $fillable = [
        'wallet_id',
        'organization_id',
        'event_id',
        'type',
        'category',
        'status',
        'amount',
        'gross_amount',
        'fee_amount',
        'source_type',
        'source_id',
        'available_at',
        'released_at',
        'created_by',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'available_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** Signed contribution of this row to its balance. */
    public function signedAmount(): float
    {
        return $this->type === 'credit'
            ? (float) $this->amount
            : -(float) $this->amount;
    }
}
