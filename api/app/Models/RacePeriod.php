<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sale window of a fun run: Early Bird, Pre Sale, Last Call.
 *
 * These rows *are* the catalogue of valid periods — see the migration for why
 * that is not a json column on `events`. The window belongs to the event; what
 * it costs belongs to each category (RaceCategoryPrice).
 *
 * Nothing here answers "which period is in force now". That is RacePricing,
 * deliberately: a second reader of the rule would drift from the one that sets
 * the price, and the drift reads as a runner charged for a period the page was
 * not showing them.
 */
class RacePeriod extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id',
        'name',
        'starts_at',
        'ends_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Both bounds are always stored as a UTC instant.
     *
     * Clients send an offset-bearing ISO string ("...T00:00:00+07:00"), because
     * early bird opens on the venue's clock, not the organizer's browser.
     * Without this, Eloquent's fromDateTime() formats that Carbon as-is and
     * lands the venue-local wall clock raw in a UTC column — 00:00 WIB reads
     * back as 17:00 the previous day, so the window silently shifts seven hours
     * and early bird ends on the wrong afternoon.
     *
     * Same fix, same reason as TicketCategory::saleStart() and
     * GameMatch::scheduledAt(). A set-only Attribute bypasses the datetime cast
     * on write and returns the DB-ready string; reads still go through the cast.
     */
    protected function startsAt(): Attribute
    {
        return self::utcInstant();
    }

    protected function endsAt(): Attribute
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

    public function prices(): HasMany
    {
        return $this->hasMany(RaceCategoryPrice::class);
    }

    /**
     * Whether this window contains the given instant, both ends inclusive.
     *
     * Inclusive on purpose, and the asymmetry it avoids is the reason: an
     * exclusive `ends_at` makes the last second of early bird a gap, and a
     * runner who hits "Daftar" at exactly 23:59:59 is told registration is
     * closed by a window that is still on screen. Organizers type round
     * numbers, so that boundary is exactly where traffic piles up.
     *
     * Mirrors TicketCategory::isOnSale(), which is `lte`/`gte` for the same
     * reason. Overlaps cannot happen — they are refused on write.
     */
    public function contains(Carbon $at): bool
    {
        return $this->starts_at->lte($at) && $this->ends_at->gte($at);
    }
}
