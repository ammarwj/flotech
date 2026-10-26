<?php

namespace App\Models;

use App\Models\Concerns\HasManualPayment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One runner's entry in a fun run.
 *
 * The fourth thing a participant can pay for, alongside TicketOrder, Team and
 * EventPlanOrder — and like the first two the money is the organizer's, so the
 * receipt carries their name (see ParticipantDocumentService).
 *
 * Its own table rather than a one-player `Team`; see the migration for why.
 * What it borrows instead is the trait: HasManualPayment keeps the
 * "awaiting verification" state derived from the proof columns rather than
 * slipped into `status` as a fourth value, which is what would walk silently
 * past every guard written as `in_array($status, [...])`.
 *
 * `period_name` and `price` are snapshots, never read through
 * `race_period_id` — organizers edit period prices mid-sale and a receipt
 * already issued must not change what it says.
 */
class RaceEntry extends Model
{
    use HasManualPayment, HasUuids;

    protected $fillable = [
        'event_id',
        'race_category_id',
        'user_id',
        'full_name',
        'email',
        'phone',
        'gender',
        'birth_date',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relation',
        'custom_fields',
        'race_period_id',
        'period_name',
        'price',
        'status',
        'payment_method',
        'payment_channel',
        // The Midtrans instrument the payment actually settled with. NOT a
        // second spelling of payment_channel above: that is the fee group the
        // buyer picked beforehand, which the gateway fee was computed from.
        'payment_type',
        'platform_fee',
        // The discount a participant typed, if any. A SNAPSHOT beside the FK,
        // not a lookup through it: an issued invoice must keep reading what was
        // actually charged even after the organizer edits or deletes the code.
        'event_discount_code_id',
        'discount_code',
        'discount_type',
        'discount_value',
        'discount_amount',
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
        // Check-in. `qr_code` is minted by markPaid() and never by hold(): a
        // credential issued before the money arrives admits somebody who has
        // not paid. `checked_in_at`/`checked_in_by` are written only by
        // RaceEntryService::checkIn(). `bib_number` has no writer yet — the
        // column is here, and what fills it is still the organizer's decision.
        'bib_number',
        'qr_code',
        'checked_in_at',
        'checked_in_by',
    ];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'birth_date' => 'date',
            'price' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'gateway_fee' => 'decimal:2',
            // Part of gateway_fee, not on top of it — never add it into gross.
            'gateway_tax' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'paid_at' => 'datetime',
            'payment_proof_uploaded_at' => 'datetime',
            'payment_deadline_at' => 'datetime',
            'verified_at' => 'datetime',
            'checked_in_at' => 'datetime',
        ];
    }

    /**
     * Unlike `teams`, where the money state is `payment_status` because
     * `status` already means the organizer admitting the squad, there is only
     * one lifecycle here and `status` is it.
     */
    protected function paymentStateColumn(): string
    {
        return 'status';
    }

    /**
     * Runner-paid total: what actually gets charged, including the fees the
     * organizer never sees. `price` stays the entry fee itself.
     */
    public function getGrossAmountAttribute(): float
    {
        return (float) $this->price + (float) $this->gateway_fee + (float) $this->service_fee;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RaceCategory::class, 'race_category_id');
    }

    /**
     * The code row this entry used, for display only.
     *
     * Nullable and `nullOnDelete`: the four snapshot columns are what every
     * reader actually prints, so a deleted code leaves the bill intact.
     */
    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(EventDiscountCode::class, 'event_discount_code_id');
    }

    /**
     * The period this entry was bought in — for display only, and nullable
     * because the period may be deleted. What was charged is `price` and what
     * it was called is `period_name`, both on this row.
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(RacePeriod::class, 'race_period_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RaceEntryDocument::class, 'race_entry_id');
    }

    /** Who checked the runner in at the start line; null until they arrive. */
    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }
}
