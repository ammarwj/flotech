<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sport an event can be held in. Rows here replace what used to be the
 * hardcoded Sports/SportStats/MatchScoring constants, so a new sport is added
 * from the admin panel rather than a deploy.
 */
class Sport extends Model
{
    use HasUuids;

    /** How a match is scored. */
    public const SCORING = ['goal', 'set'];

    protected $fillable = [
        'slug',
        'name',
        'color',
        'icon',
        'scoring',
        'default_match_minutes',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'default_match_minutes' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function stats(): HasMany
    {
        return $this->hasMany(SportStat::class)->orderBy('sort_order');
    }

    public function isSetBased(): bool
    {
        return $this->scoring === 'set';
    }
}
