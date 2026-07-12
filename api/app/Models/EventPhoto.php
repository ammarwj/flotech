<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A photo in one of an event's albums. */
class EventPhoto extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id',
        'album',
        'photo_url',
        'caption',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
