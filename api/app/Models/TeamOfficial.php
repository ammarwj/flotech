<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'custom_fields',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** This official's own documents — a coach's KTP, a certification. */
    public function documents(): HasMany
    {
        return $this->hasMany(RegistrationDocument::class, 'official_id');
    }
}
