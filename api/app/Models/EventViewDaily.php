<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One day of public page traffic for one event.
 *
 * Written exclusively by EventViewService — never mass-assign or increment
 * these counters from anywhere else, or the ledger stops matching the totals.
 */
class EventViewDaily extends Model
{
    use HasUuids;

    protected $table = 'event_view_daily';

    protected $fillable = [
        'event_id',
        'organization_id',
        'viewed_on',
        'views',
        'unique_visitors',
    ];

    protected function casts(): array
    {
        return [
            'views' => 'integer',
            'unique_visitors' => 'integer',
        ];
    }

    /**
     * Always persist a bare Y-m-d.
     *
     * EventViewService writes this column with raw SQL as a plain date, and a
     * plain `date` cast would write "2026-07-20 00:00:00" instead. Postgres
     * coerces both to the same DATE, but SQLite (what the tests run on) keeps
     * the text verbatim, so the two spellings stop comparing equal and range
     * queries silently drop rows.
     */
    protected function viewedOn(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => Carbon::parse($value)->startOfDay(),
            set: fn ($value) => Carbon::parse($value)->toDateString(),
        );
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
