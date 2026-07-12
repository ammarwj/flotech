<?php

namespace App\Models;

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

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
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
