<?php

namespace App\Models;

use App\Services\Catalog;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One competition inside an event (U17, U19, Woman, …). It owns the format,
 * bracket config, registration fee and team cap — everything that used to sit
 * on the event when an event ran a single competition.
 *
 * The scheduler, standings and knockout all operate on a category. To keep them
 * unchanged, a category mirrors the slice of the old Event interface they read:
 * `teams()`, `matches()`, `engine()`, and the sport/date accessors delegated to
 * the parent event (the sport and the calendar are shared across categories).
 */
class EventCategory extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id',
        'name',
        'slug',
        'tournament_format',
        'bracket_config',
        'registration_fee',
        'max_teams',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'bracket_config' => 'array',
            'registration_fee' => 'decimal:2',
            'max_teams' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class, 'category_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'category_id');
    }

    /**
     * The engine that runs this category's format. A format is a preset — several
     * may share one engine ("Liga" and "Liga 2 Putaran" are both `league`), so
     * scheduling and standings branch on this, never on `tournament_format`.
     */
    public function engine(): ?string
    {
        return Catalog::engineOf($this->tournament_format);
    }

    /** Catalog entry for the event's sport (name, colour, scoring, stats). */
    public function sportDefinition(): ?array
    {
        return $this->event->sportDefinition();
    }

    public function isSetBased(): bool
    {
        return $this->event->isSetBased();
    }

    /** The sport is shared by every category of the event. */
    protected function sportType(): Attribute
    {
        return Attribute::get(fn () => $this->event->sport_type);
    }

    /** The calendar is shared by every category of the event. */
    protected function startDate(): Attribute
    {
        return Attribute::get(fn () => $this->event->start_date);
    }

    protected function endDate(): Attribute
    {
        return Attribute::get(fn () => $this->event->end_date);
    }
}
