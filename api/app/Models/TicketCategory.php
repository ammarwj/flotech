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

    /**
     * Whether this category speaks about days, and how.
     *
     * `none` is today's behaviour exactly — no day rows, tickets with a null
     * `event_date`, no date comparison at the gate. See the migration for why
     * this is one column and not two booleans.
     */
    public const DAY_MODES = ['none', 'per_day', 'pass'];

    /**
     * Mirrors the column default so a freshly created category answers
     * usesDays() correctly *before* it is refreshed from the database. Without
     * it `day_mode` is null on the new instance, null is not 'none', and
     * creating a plain category walks straight into the day-sync refusal.
     */
    protected $attributes = [
        'day_mode' => 'none',
    ];

    protected $fillable = [
        'event_id',
        'day_mode',
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

    /** The dates this category sells, earliest first. */
    public function days(): HasMany
    {
        return $this->hasMany(TicketCategoryDay::class)->orderBy('event_date');
    }

    /** Whether this category sells by the day at all. */
    public function usesDays(): bool
    {
        return $this->day_mode !== 'none';
    }

    /**
     * How many ticket prices one seat costs for the given dates.
     *
     * The whole per-day/pass difference is this one number: a `per_day` buyer
     * pays once per date, a `pass` buyer pays once for all of them, and a
     * `none` category never had dates to begin with.
     */
    public function pricedDays(array $dates): int
    {
        return $this->day_mode === 'per_day' ? max(1, count($dates)) : 1;
    }

    /**
     * Seats still free on one date, or null when unlimited.
     *
     * Deliberately separate from remaining(): that one answers the category's
     * question (how many paid units are left) and this one answers the venue's
     * (how many seats are left that day). Mixing them would let a sold-out
     * Saturday be bought through on Sunday's spare capacity.
     */
    public function remainingOn(string $date): ?int
    {
        $day = $this->days->first(fn (TicketCategoryDay $d) => $d->event_date->toDateString() === $date);

        return $day?->remaining($this->quota);
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
