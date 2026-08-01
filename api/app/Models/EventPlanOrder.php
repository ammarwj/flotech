<?php

namespace App\Models;

use App\Models\Concerns\HasManualPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        return $query->where('status', 'paid')->whereNull('event_id');
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

    /** The super admin who accepted the manual transfer, if any. */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
