<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'round',
        'group_name',
        'order',
        'home_team_id',
        'away_team_id',
        'home_score',
        'away_score',
        'scheduled_at',
        'venue',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'order' => 'integer',
            'home_score' => 'integer',
            'away_score' => 'integer',
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

    public function isFinished(): bool
    {
        return $this->status === 'finished'
            && $this->home_score !== null
            && $this->away_score !== null;
    }
}
