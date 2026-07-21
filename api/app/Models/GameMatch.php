<?php

namespace App\Models;

use App\Services\RubberService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single fixture between two teams. Named GameMatch because `Match` is a
 * reserved word in PHP; the underlying table is still `matches`.
 */
class GameMatch extends Model
{
    use HasUuids;

    protected $table = 'matches';

    protected $fillable = [
        'event_id',
        'category_id',
        'stage',
        'round',
        'group_name',
        'bracket',
        'order',
        'leg',
        'home_team_id',
        'away_team_id',
        'home_score',
        'away_score',
        'home_penalty',
        'away_penalty',
        'sets',
        'scheduled_at',
        'venue',
        'status',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'order' => 'integer',
            'leg' => 'integer',
            'sets' => 'array',
            'confirmed_at' => 'datetime',
            'home_score' => 'integer',
            'away_score' => 'integer',
            'home_penalty' => 'integer',
            'away_penalty' => 'integer',
            'scheduled_at' => 'datetime',
        ];
    }

    /**
     * Kickoff is always stored as a UTC instant.
     *
     * Clients send an offset-bearing ISO string ("...T10:00:00+07:00"). Without
     * this, Eloquent's fromDateTime() formats that Carbon as-is and lands the
     * venue-local wall clock raw in a UTC column — a 10:00 WIB kickoff reads
     * back as 17:00. Same reason ScheduleService calls ->utc() on every write.
     *
     * A set-only Attribute bypasses the datetime cast on write, so this returns
     * the DB-ready string itself; reads still go through the cast.
     */
    protected function scheduledAt(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null
                ? null
                : Carbon::parse($value)->utc()->format('Y-m-d H:i:s'),
        );
    }

    /**
     * A squad tie is born with the partai its category's template calls for.
     *
     * Hooked here rather than at each call site because fixtures are created in
     * ten places (ScheduleService's league/knockout/hybrid paths, manual entry,
     * bracket seeding) and every one of them would have to remember. The service
     * no-ops for every category that isn't a racket-sport squad tie.
     *
     * Model events are the hook, so anything running WithoutModelEvents (the
     * seeders) has to call RubberService::seedFor() itself — same caveat that
     * makes Catalog::flush() explicit there.
     */
    protected static function booted(): void
    {
        static::created(fn (GameMatch $match) => app(RubberService::class)->seedFor($match));
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function rubbers(): HasMany
    {
        return $this->hasMany(MatchRubber::class, 'match_id')->orderBy('order');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(PlayerMatchStat::class, 'match_id');
    }

    public function isFinished(): bool
    {
        return $this->status === 'finished'
            && $this->home_score !== null
            && $this->away_score !== null;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
