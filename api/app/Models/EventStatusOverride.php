<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One super-admin force-set of `events.status`, bypassing `Event::TRANSITIONS`.
 * See the create_event_status_overrides_table migration for why this is its
 * own ledger rather than a column on `events`.
 */
class EventStatusOverride extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id',
        'from_status',
        'to_status',
        'reason',
        'created_by',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** Whoever typed this. Named `author` because `createdBy` reads as a column. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
