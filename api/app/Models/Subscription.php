<?php

namespace App\Models;

use App\Models\Concerns\HasManualPayment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasManualPayment, HasUuids;

    protected $fillable = [
        'organization_id',
        'plan_id',
        'invoice_number',
        'receipt_number',
        'billing_cycle',
        'amount',
        'status',
        'starts_at',
        'expires_at',
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
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'payment_proof_uploaded_at' => 'datetime',
            'payment_deadline_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * A subscription has no separate payment column: being paid for *is* being
     * active. Everything else about manual transfer comes from the trait.
     */
    protected function paymentStateColumn(): string
    {
        return 'status';
    }

    /**
     * Consequence for scopeAwaitingVerification(): `status != 'active'` also
     * lets `cancelled` rows through. One with a proof attached cannot exist —
     * the only two writers of `cancelled` are the Midtrans webhook, which
     * matches on `midtrans_order_id` and so never touches a manual row (they
     * have none), and `subscriptions:expire-manual`, which skips anything that
     * already has a proof. Loosen that sweep and the super admin's queue
     * quietly fills with dead invoices.
     */
    protected function settledValue(): string
    {
        return 'active';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** The super admin who accepted the manual transfer, if any. */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
