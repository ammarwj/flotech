<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * The sale window is always stored as a UTC instant.
     *
     * Clients send an offset-bearing ISO string ("...T10:00:00+07:00"), because
     * a sale opens on the venue's clock, not the organizer's browser. Without
     * this, Eloquent's fromDateTime() formats that Carbon as-is and lands the
     * venue-local wall clock raw in a UTC column — 10:00 WIB reads back as
     * 17:00, so the time appears to jump forward on every save.
     *
     * Same fix, same reason as GameMatch::scheduledAt(). A set-only Attribute
     * bypasses the datetime cast on write and returns the DB-ready string;
     * reads still go through the cast.
     */
    protected function saleStart(): Attribute
    {
        return self::utcInstant();
    }

    protected function saleEnd(): Attribute
    {
        return self::utcInstant();
    }

    private static function utcInstant(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null
                ? null
                : Carbon::parse($value)->utc()->format('Y-m-d H:i:s'),
        );
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
