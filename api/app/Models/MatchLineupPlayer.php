<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player named on one team sheet, as a starter or a substitute.
 *
 * Everything else about them — shirt number, position, name — stays on their
 * roster row. Copying any of it here would let the sheet and the roster disagree,
 * and the sheet is the copy nobody would go back to fix.
 */
class MatchLineupPlayer extends Model
{
    use HasUuids;

    public const ROLES = ['starter', 'substitute'];

    protected $fillable = [
        'lineup_id',
        'player_id',
        'role',
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

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
