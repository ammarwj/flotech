<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file a runner uploaded with their entry — a student card, a health note.
 *
 * Its own table rather than a fourth scope on `registration_documents`, and the
 * two reasons are in the migration: that column is `team_id NOT NULL`, and
 * MediaCleanupService sweeps those rows *by* `team_id`, so a row with no team
 * would be invisible to the sweeper and its file would outlive the event in R2
 * forever.
 */
class RaceEntryDocument extends Model
{
    use HasUuids;

    protected $fillable = [
        'race_entry_id',
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

    public function entry(): BelongsTo
    {
        return $this->belongsTo(RaceEntry::class, 'race_entry_id');
    }
}
