<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card shown to someone on a team's bench in one fixture.
 *
 * Sibling of PlayerMatchStat, and a separate model for the reason the tables are
 * separate — see the create_official_match_stats_table migration. Only stats
 * carrying the sport's `yellow`/`red` role are ever written here.
 */
class OfficialMatchStat extends Model
{
    use HasUuids;

    protected $fillable = [
        'match_id',
        'team_id',
        'team_official_id',
        'stat_key',
        'value',
    ];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function official(): BelongsTo
    {
        return $this->belongsTo(TeamOfficial::class, 'team_official_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
