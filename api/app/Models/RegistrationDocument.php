<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationDocument extends Model
{
    use HasUuids;

    protected $fillable = [
        'team_id',
        'player_id',
        'document_type',
        'file_url',
        'file_name',
        'file_size_bytes',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    /**
     * Always set, including on a player's document. MediaCleanupService sweeps
     * this table by team_id, so keeping it filled is what lets player documents
     * be cleaned up without that service learning this feature exists.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** Null on a team document (mandate letter); set on a player's (KTP). */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
