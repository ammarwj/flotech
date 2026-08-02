<?php

namespace App\Models;

use App\Models\Concerns\HasManualPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One purchase of one plan, for one event.
 *
 * A paid order is a *credit*: it entitles nothing on its own. Creating an event
 * spends it, which stamps `event_id` and copies the plan onto `events.plan_id`.
 * From then on the event carries the entitlement and this row is the receipt.
 *
 * There is no billing period. An event that runs across a month boundary costs
 * exactly what it cost on the day it was bought.
 */
class EventPlanOrder extends Model
{
    use HasManualPayment, HasUuids;

    protected $fillable = [
        'organization_id',
        'plan_id',
        'event_id',
        'consumed_at',
        'upgrade_of_id',
        'invoice_number',
        'receipt_number',
        'amount',
        'status',
        'midtrans_order_id',
        'payment_type',
        'midtrans_token',
        'paid_at',
        'payment_method',
        'payment_proof_url',
        'payment_proof_uploaded_at',
        'payment_deadline_at',
        'rejected_reason',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'consumed_at' => 'datetime',
            'paid_at' => 'datetime',
            'payment_proof_uploaded_at' => 'datetime',
            'payment_deadline_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * A plan order has no separate payment column: being paid for *is* its
     * whole state. Everything else about manual transfer comes from the trait.
     */
    protected function paymentStateColumn(): string
    {
        return 'status';
    }

    /**
     * Consequence for scopeAwaitingVerification(): `status != 'paid'` also lets
     * `cancelled` rows through. One with a proof attached cannot exist — the
     * only two writers of `cancelled` are the Midtrans webhook, which matches on
     * `midtrans_order_id` and so never touches a manual row (they have none),
     * and `plan-orders:expire-manual`, which skips anything that already has a
     * proof. Loosen that sweep and the super admin's queue quietly fills with
     * dead invoices.
     */
    protected function settledValue(): string
    {
        return 'paid';
    }

    /**
     * Paid for, not yet spent on an event — the credit an organizer holds.
     *
     * These are never expired by the sweep: the organizer already paid, and
     * taking the credit back is taking money.
     */
    public function scopeUnconsumed(Builder $query): Builder
    {
        return $query->where('status', 'paid')
            ->whereNull('event_id')
            // An order that has been upgraded is not a credit any more. The
            // organizer only paid the difference, so its money is already spent
            // on whatever the successor now holds; handing it back to the pool
            // would be handing out a free event. This is the one place upgrade
            // parts ways with reassign-plan, where both orders were paid in
            // full and releasing the old one is correct.
            //
            // Read off the successor row rather than a flag here, so there is no
            // second copy of the fact that can drift out of step.
            ->whereDoesntHave('upgrades', fn (Builder $q) => $q->where('status', 'paid'));
    }

    /** True once a paid upgrade has taken this order's place. */
    public function isSuperseded(): bool
    {
        return $this->upgrades()->where('status', 'paid')->exists();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** The event this credit was spent on, or null while it is still unspent. */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** The order this one upgrades — null on ordinary purchases. */
    public function upgradeOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'upgrade_of_id');
    }

    /**
     * Top-up bills raised against this order.
     *
     * hasMany, not a `latestOfMany` hasOne: scopeUnconsumed() asks this through
     * `whereDoesntHave`, and a one-of-many relation carries an aggregate
     * subquery that existence checks cannot see through — the exclusion silently
     * matched nothing. At most one row here is ever paid, because
     * checkoutUpgrade() reopens an outstanding attempt instead of raising a
     * second one and refuses outright once a paid one exists.
     */
    public function upgrades(): HasMany
    {
        return $this->hasMany(self::class, 'upgrade_of_id');
    }

    /** The super admin who accepted the manual transfer, if any. */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
