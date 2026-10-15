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

    /**
     * How a match is scored — and whether there is a match at all.
     *
     * `none` is the third answer and it is not a scoring style: it says this
     * sport holds no fixtures, no table and no bracket (a fun run, a charity
     * ride). One column rather than a separate `is_competitive` boolean beside
     * it, because a boolean would be a second reader of a question this column
     * already answers, and the two would drift the way two readers of `stage`
     * did. Every gate asking "is there anything to schedule here?" goes through
     * Catalog::isCompetitive(); the ones asking "does this run on a clock?" read
     * `=== 'goal'` positively — never `! isSetBased()`, which lets `none`
     * through as enabled.
     *
     * SportRequest validates against this constant, so widening it here is the
     * whole of the server-side change.
     */
    public const SCORING = ['goal', 'set', 'none'];

    /** What a single entrant is: one player, a pair, or a squad. */
    public const MODES = ['single', 'double', 'team'];

    protected $fillable = [
        'slug',
        'name',
        'color',
        'icon',
        'scoring',
        'participant_modes',
        'discipline_config',
        'period_config',
        'squad_config',
        'default_match_minutes',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'participant_modes' => 'array',
            'discipline_config' => 'array',
            'period_config' => 'array',
            'squad_config' => 'array',
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

    /**
     * This sport's default card thresholds. Empty means "no opinion" — the
     * fallbacks in DisciplineRules::DEFAULTS apply — which is also what a sport
     * that books nobody returns.
     *
     * @return array<string, mixed>
     */
    public function disciplineConfig(): array
    {
        return $this->discipline_config ?: [];
    }

    /**
     * Bentuk babak cabang ini. Kosong berarti "tidak berpendapat" — fallback di
     * MatchClockRules::DEFAULTS yang berlaku — dan itu juga yang dikembalikan
     * cabang set, yang tidak punya babak sama sekali.
     *
     * @return array<string, mixed>
     */
    public function periodConfig(): array
    {
        return $this->period_config ?: [];
    }

    /**
     * Bentuk susunan pemain cabang ini. Kosong berarti "tidak berpendapat" —
     * fallback di `SquadRules::DEFAULTS` yang berlaku — dan itu juga yang
     * dikembalikan cabang set, yang tidak menurunkan inti/cadangan sama sekali.
     *
     * @return array<string, mixed>
     */
    public function squadConfig(): array
    {
        return $this->squad_config ?: [];
    }

    public function stats(): HasMany
    {
        return $this->hasMany(SportStat::class)->orderBy('sort_order');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(SportPosition::class)->orderBy('sort_order');
    }

    public function officialRoles(): HasMany
    {
        return $this->hasMany(SportOfficialRole::class)->orderBy('sort_order');
    }

    public function isSetBased(): bool
    {
        return $this->scoring === 'set';
    }
}
