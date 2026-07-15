<?php

namespace App\Support;

use App\Models\EventCategory;
use App\Services\Catalog;

/**
 * Format configuration for an event, stored in `events.bracket_config`.
 *
 * Everything is optional — the defaults describe a plain single round-robin
 * with 3/1/0 points, so league events can read the same object without ever
 * having been configured.
 *
 * The *vocabulary* (which knockout rounds, tiebreakers and draw methods exist)
 * comes from the catalog, so an admin can rename, reorder or disable them.
 */
class HybridConfig
{
    public function __construct(
        public readonly int $groups = 4,
        public readonly int $teamsPerGroup = 4,
        public readonly bool $homeAway = false,
        public readonly int $legs = 1,
        public readonly int $pointsWin = 3,
        public readonly int $pointsDraw = 1,
        public readonly int $pointsLose = 0,
        /** Teams qualifying straight from each group's top places (1..3). */
        public readonly int $topPerGroup = 2,
        /** Extra qualifiers taken from the best 2nd-placed teams across groups. */
        public readonly int $bestRunnersUp = 0,
        /** Extra qualifiers taken from the best 3rd-placed teams across groups. */
        public readonly int $bestThirds = 0,
        /** Entry round for the knockout stage, or null to size it automatically. */
        public readonly ?string $knockoutStart = null,
        public readonly string $drawMethod = 'random',
        /** @var array<int, string> */
        public readonly array $tiebreakers = ['head_to_head', 'goal_difference', 'goals_scored', 'fair_play', 'drawing_lots'],
    ) {}

    public static function fromCategory(EventCategory $category): self
    {
        return self::fromArray(is_array($category->bracket_config) ? $category->bracket_config : []);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $points = is_array($raw['points'] ?? null) ? $raw['points'] : [];
        $qual = is_array($raw['qualification'] ?? null) ? $raw['qualification'] : [];

        $known = Catalog::keys('tiebreaker');
        $drawMethods = Catalog::keys('draw_method');
        $rounds = array_keys(Catalog::roundSizes());

        // Keep only tiebreakers the catalog still offers, in the event's order.
        $tiebreakers = array_values(array_intersect(
            is_array($raw['tiebreakers'] ?? null) ? $raw['tiebreakers'] : $known,
            $known,
        ));

        $homeAway = (bool) ($raw['home_away'] ?? false);

        return new self(
            groups: max(1, (int) ($raw['groups'] ?? 4)),
            teamsPerGroup: max(2, (int) ($raw['teams_per_group'] ?? 4)),
            homeAway: $homeAway,
            // Home & away implies two legs; an explicit `legs` still wins.
            legs: max(1, min(2, (int) ($raw['legs'] ?? ($homeAway ? 2 : 1)))),
            pointsWin: (int) ($points['win'] ?? 3),
            pointsDraw: (int) ($points['draw'] ?? 1),
            pointsLose: (int) ($points['lose'] ?? 0),
            topPerGroup: max(1, min(3, (int) ($qual['top_per_group'] ?? 2))),
            bestRunnersUp: max(0, (int) ($qual['best_runners_up'] ?? 0)),
            bestThirds: max(0, (int) ($qual['best_thirds'] ?? 0)),
            knockoutStart: in_array($raw['knockout_start'] ?? null, $rounds, true)
                ? $raw['knockout_start']
                : null,
            drawMethod: in_array($raw['draw_method'] ?? null, $drawMethods, true)
                ? $raw['draw_method']
                : ($drawMethods[0] ?? 'random'),
            tiebreakers: $tiebreakers ?: $known,
        );
    }

    /** Teams expected in the group stage. */
    public function totalTeams(): int
    {
        return $this->groups * $this->teamsPerGroup;
    }

    /**
     * How many teams reach the knockout stage: the automatic places per group
     * plus the best-ranked extras.
     */
    public function qualifierCount(): int
    {
        // Extras only count when that place doesn't already qualify: a "best
        // runner-up" is meaningless if every runner-up goes through anyway.
        // Mirrors qualifierSlots(), which skips them for the same reason.
        $extras = ($this->topPerGroup < 2 ? $this->bestRunnersUp : 0)
            + ($this->topPerGroup < 3 ? $this->bestThirds : 0);

        return $this->groups * $this->topPerGroup + $extras;
    }

    /**
     * Bracket size for the knockout stage: the configured entry round, or the
     * next power of two above the qualifier count. Teams beyond the bracket
     * size never happen (we cap qualifiers); a smaller field gets BYEs.
     */
    public function bracketSize(): int
    {
        $qualifiers = max(2, $this->qualifierCount());

        if ($this->knockoutStart !== null) {
            return max((int) Catalog::roundSize($this->knockoutStart), 2);
        }

        $size = 2;
        while ($size < $qualifiers) {
            $size *= 2;
        }

        return $size;
    }

    /** Group labels: A, B, C, … */
    public function groupNames(): array
    {
        return array_map(fn ($i) => chr(65 + $i), range(0, $this->groups - 1));
    }

    /**
     * Validation rules for the `bracket_config` payload on event create/update.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'bracket_config' => ['nullable', 'array'],
            'bracket_config.groups' => ['nullable', 'integer', 'min:1', 'max:32'],
            'bracket_config.teams_per_group' => ['nullable', 'integer', 'min:2', 'max:16'],
            'bracket_config.home_away' => ['nullable', 'boolean'],
            'bracket_config.legs' => ['nullable', 'integer', 'min:1', 'max:2'],
            'bracket_config.points' => ['nullable', 'array'],
            'bracket_config.points.win' => ['nullable', 'integer', 'min:0', 'max:10'],
            'bracket_config.points.draw' => ['nullable', 'integer', 'min:0', 'max:10'],
            'bracket_config.points.lose' => ['nullable', 'integer', 'min:0', 'max:10'],
            'bracket_config.qualification' => ['nullable', 'array'],
            'bracket_config.qualification.top_per_group' => ['nullable', 'integer', 'min:1', 'max:3'],
            'bracket_config.qualification.best_runners_up' => ['nullable', 'integer', 'min:0', 'max:32'],
            'bracket_config.qualification.best_thirds' => ['nullable', 'integer', 'min:0', 'max:32'],
            'bracket_config.knockout_start' => ['nullable', 'in:'.implode(',', array_keys(Catalog::roundSizes()))],
            'bracket_config.draw_method' => ['nullable', 'in:'.implode(',', Catalog::keys('draw_method'))],
            'bracket_config.tiebreakers' => ['nullable', 'array'],
            'bracket_config.tiebreakers.*' => ['in:'.implode(',', Catalog::keys('tiebreaker'))],
        ];
    }
}
