<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketCategory extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id',
        'name',
        'description',
        'price',
        'quota',
        'sold',
        'sale_start',
        'sale_end',
        'benefits',
        'is_transferable',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'quota' => 'integer',
            'sold' => 'integer',
            'sale_start' => 'datetime',
            'sale_end' => 'datetime',
            'benefits' => 'array',
            'is_transferable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(TicketOrder::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** Tickets still available, or null when the quota is unlimited. */
    public function remaining(): ?int
    {
        return $this->quota === null ? null : max(0, $this->quota - $this->sold);
    }

    /** Whether the category is currently on sale (active + inside its window). */
    public function isOnSale(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        return (! $this->sale_start || $this->sale_start->lte($now))
            && (! $this->sale_end || $this->sale_end->gte($now));
    }
}
