<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    use HasUuids;

    protected $fillable = [
        'team_id',
        'full_name',
        'jersey_number',
        'position',
        'date_of_birth',
        'photo_url',
        'custom_fields',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'custom_fields' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * This player's own documents — a KTP belongs to a person, not a squad.
     * The team's documents are the rows on the same table with a null player_id;
     * see Team::documents(), which deliberately still covers both so media
     * cleanup keeps working from the team alone.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(RegistrationDocument::class);
    }
}
