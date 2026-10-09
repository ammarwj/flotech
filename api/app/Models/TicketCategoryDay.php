<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day a ticket category sells, with that day's own capacity.
 *
 * These rows *are* the catalogue of valid dates — see the migration for why
 * that is not a json column — and `sold` here is the seat count for this date
 * alone, which is a different question from `ticket_categories.sold`.
 */
class TicketCategoryDay extends Model
{
    use HasUuids;

    protected $fillable = [
        'ticket_category_id',
        'event_date',
        'quota',
        'sold',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'quota' => 'integer',
            'sold' => 'integer',
        ];
    }

    /**
     * Stored as a bare `Y-m-d`, never `Y-m-d 00:00:00`.
     *
     * Eloquent's `date` cast writes a full datetime string, which makes every
     * plain `where('event_date', '2026-11-14')` miss — and a miss here is
     * silent in the worst way: `firstOrCreate` inserts a duplicate (caught only
     * by the unique index) and a release finds no row to give seats back to. A
     * `date` column has no time to carry, so the midnight is noise that only
     * ever breaks comparisons. Same set-only shape as TicketCategory's sale
     * window, opposite problem.
     */
    protected function eventDate(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => Carbon::parse($value)->format('Y-m-d'),
        );
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'ticket_category_id');
    }

    /**
     * Seats still free on this date, or null when unlimited.
     *
     * `$categoryQuota` is passed in rather than read off the relation: this is
     * called once per date in a list the caller already has loaded, and a
     * lazy `$this->category` there would be an N+1 per day of the event.
     */
    public function remaining(?int $categoryQuota): ?int
    {
        $quota = $this->quota ?? $categoryQuota;

        return $quota === null ? null : max(0, $quota - $this->sold);
    }
}
