<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketOrder extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id',
        'ticket_category_id',
        'buyer_user_id',
        'buyer_name',
        'buyer_email',
        'buyer_phone',
        'quantity',
        'unit_price',
        'total_price',
        'platform_fee',
        'status',
        'midtrans_order_id',
        'midtrans_token',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'ticket_category_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'order_id');
    }
}
