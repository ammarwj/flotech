<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money owed back after a refund, and which of its two hops it is waiting on.
 *
 * See the migration for why this is one source-keyed table rather than columns
 * on three, why the amount is read from the ledger instead of recomputed, and
 * why completing one must never touch the wallet.
 *
 * The two hops are deliberately asymmetric. Hop one is the platform's debt to
 * the organizer and is the only one `status` tracks, because it is the one a
 * super admin works a queue of. Hop two is the organizer's debt to their own
 * participant, and it is derived from `forwarded_at` rather than given a
 * `status` value of its own — the same call `race_entries` makes for its refund
 * conversation, and for the same reason: three services guard on
 * `in_array($this->status, [...])` there, and a fourth value walks past all of
 * them. Here there is only one guard, but the shape is worth keeping: `status`
 * says what the PLATFORM still owes, `forwarded_at` says what the ORGANIZER
 * still owes, and no single column has to mean both.
 */
class RefundPayout extends Model
{
    use HasUuids;

    protected $fillable = [
        'source_type',
        'source_id',
        'organization_id',
        'event_id',
        'wallet_transaction_id',
        'amount',
        'recipient_name',
        'reason',
        'status',
        'account_type',
        'bank_name',
        'bank_code',
        'account_number',
        'account_holder',
        'proof_url',
        'transfer_reference',
        'admin_note',
        'completed_by',
        'completed_at',
        'forwarded_proof_url',
        'forwarded_reference',
        'forwarded_by',
        'forwarded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'completed_at' => 'datetime',
            'forwarded_at' => 'datetime',
        ];
    }

    /**
     * The human labels for each source rail.
     *
     * Spelled here rather than in the resource so the admin queue and the
     * organizer's own screen cannot disagree about what a row is.
     *
     * @var array<string, string>
     */
    public const SOURCE_LABELS = [
        'race_entry' => 'Pendaftaran lomba',
        'team' => 'Pendaftaran tim',
        'ticket_order' => 'Pesanan tiket',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function forwarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_by');
    }

    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->source_type] ?? $this->source_type;
    }

    /** The platform still owes this transfer. */
    public function isAwaitingTransfer(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * The organizer has the money and has not said it reached the participant.
     *
     * Derived from the two columns rather than stored, so it cannot disagree
     * with either of them. A pending row is not "awaiting forwarding" — the
     * organizer has nothing to forward yet.
     */
    public function isAwaitingForwarding(): bool
    {
        return $this->status === 'completed' && $this->forwarded_at === null;
    }

    /**
     * The platform's queue, oldest first.
     *
     * A scope rather than a `where` at each call site, the same reasoning as
     * `scopeAwaitingRefundDecision()`: the queue screen and the badge count
     * above it must not be able to disagree about which rows are waiting.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<RefundPayout>  $query
     * @return \Illuminate\Database\Eloquent\Builder<RefundPayout>
     */
    public function scopeAwaitingTransfer($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<RefundPayout>  $query
     * @return \Illuminate\Database\Eloquent\Builder<RefundPayout>
     */
    public function scopeAwaitingForwarding($query)
    {
        return $query->where('status', 'completed')->whereNull('forwarded_at');
    }
}
