<?php

namespace App\Models;

use App\Models\Concerns\HasManualPayment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use HasManualPayment, HasUuids;

    protected $fillable = [
        'event_id',
        'category_id',
        'name',
        'logo_url',
        'contact_name',
        'contact_phone',
        'status',
        'group_name',
        'seed_pot',
        'registered_at',
        'approved_at',
        'manager_user_id',
        'payment_status',
        'payment_amount',
        'platform_fee',
        'payment_method',
        'midtrans_order_id',
        'midtrans_token',
        'paid_at',
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
            'registered_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'payment_amount' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'payment_proof_uploaded_at' => 'datetime',
            'payment_deadline_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    protected function paymentStateColumn(): string
    {
        return 'payment_status';
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RegistrationDocument::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }
}
