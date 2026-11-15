<?php

namespace App\Models;

use App\Models\Concerns\HasManualPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move of a runner's entry from one race category to another.
 *
 * See the migration for the three invariants this carries. The one worth
 * repeating at the call site: `race_entries.price` is never rewritten, so this
 * table is the only thing that can answer how much a runner has actually paid
 * — and `paidTowards()` below is the only place that answers it.
 *
 * Borrows HasManualPayment for the same reason the other four rails do: a
 * top-up can be transferred by hand and an org admin rules on the receipt, and
 * "awaiting verification" must stay derived from the proof columns rather than
 * slipped into `status`, where it would walk past every guard written as
 * `in_array($status, [...])`.
 */
class RaceEntryTransfer extends Model
{
    use HasManualPayment, HasUuids;

    /** The runner owes the difference before the move takes effect. */
    public const UP = 'up';

    /** The difference comes back to them; the move takes effect at once. */
    public const DOWN = 'down';

    /** Same price both sides. Settled on the spot, no document. */
    public const EVEN = 'even';

    protected $fillable = [
        'race_entry_id',
        'event_id',
        'from_category_id',
        'to_category_id',
        // The ENTRY's window, not today's — see the migration.
        'race_period_id',
        'period_name',
        'from_price',
        'to_price',
        // What the runner had handed over when this was quoted, kept so one row
        // can be audited without replaying the whole chain.
        'paid_before',
        'direction',
        'amount',
        'status',
        'payment_method',
        'payment_channel',
        'payment_type',
        'gateway_fee',
        'gateway_tax',
        'service_fee',
        'invoice_number',
        'receipt_number',
        'midtrans_order_id',
        'midtrans_token',
        'paid_at',
        'payment_proof_url',
        'payment_proof_uploaded_at',
        'payment_deadline_at',
        'rejected_reason',
        'verified_by',
        'verified_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'from_price' => 'decimal:2',
            'to_price' => 'decimal:2',
            'paid_before' => 'decimal:2',
            'amount' => 'decimal:2',
            'gateway_fee' => 'decimal:2',
            // Inside gateway_fee, never on top of it.
            'gateway_tax' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'paid_at' => 'datetime',
            'payment_proof_uploaded_at' => 'datetime',
            'payment_deadline_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * What this runner has actually handed over for their place, all moves in.
     *
     * The mirror of `EventPlanOrder::paidTowardsPlan()`, and load-bearing for
     * the same reason: the difference owed on the NEXT move has to be measured
     * against everything already paid, not against the entry's own `price`.
     * After 5K→10K a runner has paid 100k + 100k; quoting a jump to 21K off
     * `price` alone bills the first 100k twice. Test it by COMPARING a
     * 5K→10K→21K chain with a direct 21K entry.
     *
     * Settled transfers only, and downward ones subtract: a refunded difference
     * is money that went back, so counting it would have the runner credited
     * for cash they no longer hold. `loadMissing` rather than a fresh query, so
     * a caller that already eager-loaded the chain pays for one.
     */
    public static function paidTowards(RaceEntry $entry): float
    {
        $entry->loadMissing('transfers');

        $moves = $entry->transfers
            ->where('status', 'paid')
            ->sum(fn (self $transfer) => $transfer->signedAmount());

        return round((float) $entry->price + $moves, 2);
    }

    /**
     * This move's signed contribution to what the runner has paid.
     *
     * `amount` is always positive and `direction` gives it the sign, the shape
     * `WalletTransaction::signedAmount()` already uses — a signed column is one
     * some reader forgets to sign.
     */
    public function signedAmount(): float
    {
        return $this->direction === self::DOWN
            ? -(float) $this->amount
            : (float) $this->amount;
    }

    /** Still waiting on the runner's money. */
    public function isOpen(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * The sweep's and the guard's shared question.
     *
     * A scope rather than a `where` at each call site, the same reasoning as
     * `scopeAwaitingVerification()`: the proof page, the guard that refuses a
     * second move and the hourly sweep must not disagree about which rows are
     * still open.
     *
     * @param  Builder<RaceEntryTransfer>  $query
     * @return Builder<RaceEntryTransfer>
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * `race_entry_transfers.status` is the one lifecycle column here, like
     * `race_entries` and unlike `teams` — there is no organizer admitting
     * anybody, only money arriving.
     */
    protected function paymentStateColumn(): string
    {
        return 'status';
    }

    /**
     * What the runner is actually charged for the top-up: the difference plus
     * the fees the organizer never sees. Mirrors
     * `RaceEntry::getGrossAmountAttribute()`.
     */
    public function getGrossAmountAttribute(): float
    {
        return (float) $this->amount + (float) $this->gateway_fee + (float) $this->service_fee;
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(RaceEntry::class, 'race_entry_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function fromCategory(): BelongsTo
    {
        return $this->belongsTo(RaceCategory::class, 'from_category_id');
    }

    public function toCategory(): BelongsTo
    {
        return $this->belongsTo(RaceCategory::class, 'to_category_id');
    }

    /**
     * The window the two prices were read in — display only, and nullable
     * because a period may be deleted. What was compared is `from_price` /
     * `to_price` and what the window was called is `period_name`, both on this
     * row.
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(RacePeriod::class, 'race_period_id');
    }

    /**
     * The transfer owed back after a downward move, if any.
     *
     * Source-keyed rather than an FK, because one table serves every refund
     * rail — see the `refund_payouts` migration. Absent for an upward or even
     * move, and for a downward one whose money never passed through the
     * platform (manual rail, or a credit still pending when it was reversed).
     */
    public function refundPayout()
    {
        return $this->hasOne(RefundPayout::class, 'source_id')
            ->where('source_type', 'race_entry_transfer');
    }
}
