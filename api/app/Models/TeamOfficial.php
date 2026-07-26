<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone on a team's bench: pelatih, manajer, ofisial. Deliberately not a
 * Player — see the create_team_officials_table migration for the five readers
 * that would misbehave if the two shared a table.
 */
class TeamOfficial extends Model
{
    use HasUuids;

    protected $fillable = [
        'team_id',
        'full_name',
        'role',
        'photo_url',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
