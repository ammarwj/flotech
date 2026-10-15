<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A category a fun run sells entries in: Mahasiswa, Umum, 5K, 10K.
 *
 * Its own table rather than `event_categories` — see the migration. Nothing
 * here knows about formats, brackets, rosters or standings, which is the point:
 * the nine readers of a competition category never have to grow a branch for a
 * sport that holds no matches.
 *
 * There is deliberately no `price` on this model. What an entry costs depends on
 * when it is bought, and RacePricing is the only thing that answers it.
 */
class RaceCategory extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id',
        'name',
        'slug',
        'description',
        'quota',
        'taken',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quota' => 'integer',
            'taken' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(RaceEntry::class, 'race_category_id');
    }

    /** This category's price for each period of its event, keyed by nothing — read via RacePricing. */
    public function prices(): HasMany
    {
        return $this->hasMany(RaceCategoryPrice::class, 'race_category_id');
    }

    /**
     * Places still free, or null when the quota is unlimited.
     *
     * Mirrors TicketCategory::remaining(). `taken` is the reservation, not the
     * paid count: an unpaid entry really is holding a place until it expires,
     * which is what race-entries:expire-manual is for.
     */
    public function remaining(): ?int
    {
        return $this->quota === null ? null : max(0, $this->quota - $this->taken);
    }

    /**
     * Whether this category can be entered at all right now.
     *
     * Only the half that belongs to the category: active, and with room. The
     * other half — "is any period in force, and does this category have a price
     * during it" — is RacePricing's, because it needs the event's windows.
     * Folding them into one boolean here would make this model the second
     * reader of the pricing rule.
     */
    public function isOpen(): bool
    {
        return $this->is_active && ($this->remaining() === null || $this->remaining() > 0);
    }
}
