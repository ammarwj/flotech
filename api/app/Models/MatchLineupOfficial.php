<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bench official named on one team sheet — pelatih, manajer, ofisial.
 *
 * Its own table, not a row in match_lineup_players with a nullable column: that
 * is the "ofisial bukan pemain" invariant, and a nullable discriminator is how it
 * leaks back.
 */
class MatchLineupOfficial extends Model
{
    use HasUuids;

    protected $fillable = [
        'lineup_id',
        'team_official_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function lineup(): BelongsTo
    {
        return $this->belongsTo(MatchLineup::class, 'lineup_id');
    }

    public function official(): BelongsTo
    {
        return $this->belongsTo(TeamOfficial::class, 'team_official_id');
    }
}
