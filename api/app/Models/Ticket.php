<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id',
        'ticket_category_id',
        'event_id',
        'qr_code',
        'holder_name',
        'is_used',
        'used_at',
        'used_by',
    ];

    protected function casts(): array
    {
        return [
            'is_used' => 'boolean',
            'used_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(TicketOrder::class, 'order_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'ticket_category_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }
}
