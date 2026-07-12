<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One statistic column of a sport (goals, assists, aces…). The lowest
 * sort_order is the primary stat the leaderboard ranks by.
 */
class SportStat extends Model
{
    use HasUuids;

    /** Meanings the engine understands; anything else is a plain counter. */
    public const ROLES = ['goal', 'assist'];

    protected $fillable = [
        'sport_id',
        'stat_key',
        'label',
        'short',
        'role',
        'fair_play_weight',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'fair_play_weight' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }
}
