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

    /** What a single entrant is: one player, a pair, or a squad. */
    public const MODES = ['single', 'double', 'team'];

    protected $fillable = [
        'slug',
        'name',
        'color',
        'icon',
        'scoring',
        'participant_modes',
        'default_match_minutes',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'participant_modes' => 'array',
            'default_match_minutes' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Squad-only unless the admin said otherwise — a sport predating this column
     * fields teams, never lone players.
     *
     * @return array<int, string>
     */
    public function participantModes(): array
    {
        return $this->participant_modes ?: ['team'];
    }

    public function supportsMode(string $mode): bool
    {
        return in_array($mode, $this->participantModes(), true);
    }

    public function stats(): HasMany
    {
        return $this->hasMany(SportStat::class)->orderBy('sort_order');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(SportPosition::class)->orderBy('sort_order');
    }

    public function isSetBased(): bool
    {
        return $this->scoring === 'set';
    }
}
