<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One partai inside a squad-vs-squad tie: "Ganda Putra — Dimas/Ammar vs
 * Ucang/Devan, 21-16 / 22-20". The tie's scoreline is the count of these that
 * each side won; see MatchScoring::rubbersWon().
 */
class MatchRubber extends Model
{
    use HasUuids;

    /** How many players a side fields in this partai. */
    public const TYPES = ['single' => 1, 'double' => 2];

    protected $fillable = [
        'match_id',
        'order',
        'label',
        'type',
        'home_player_ids',
        'away_player_ids',
        'sets',
        'home_score',
        'away_score',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'home_player_ids' => 'array',
            'away_player_ids' => 'array',
            'sets' => 'array',
            'home_score' => 'integer',
            'away_score' => 'integer',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    /** How many players this partai fields per side. */
    public function lineupSize(): int
    {
        return self::TYPES[$this->type] ?? 1;
    }

    public function isPlayed(): bool
    {
        return $this->home_score !== null && $this->away_score !== null;
    }
}
