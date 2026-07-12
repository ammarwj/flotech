<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sponsor's logo on an event page. */
class EventSponsor extends Model
{
    use HasUuids;

    /** Partner tiers, most prominent first. */
    public const TIERS = ['host', 'sponsor', 'media_partner', 'supporter'];

    protected $fillable = [
        'event_id',
        'name',
        'logo_url',
        'website_url',
        'tier',
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
