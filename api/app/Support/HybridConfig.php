<?php

namespace App\Support;

use App\Models\EventCategory;
use App\Services\Catalog;

/**
 * Format configuration for an event, stored in `events.bracket_config`.
 *
 * Everything is optional — the defaults describe a plain single round-robin, so
 * league events can read the same object without ever having been configured.
 *
 * The *vocabulary* (which knockout rounds, tiebreakers and draw methods exist)
 * comes from the catalog, so an admin can rename, reorder or disable them. Both
 * that vocabulary and the points defaults follow the standings context: a
 * badminton event is offered "Selisih Game", never "Selisih Gol".
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
        /** Play an extra tie between the beaten semifinalists. */
        public readonly bool $thirdPlace = false,
        /** @var array<int, string> */
        public readonly array $tiebreakers = ['head_to_head', 'goal_difference', 'goals_scored', 'fair_play', 'penalty_shootout', 'drawing_lots'],
    ) {}

    public static function fromCategory(EventCategory $category): self
    {
        return self::fromArray(
            is_array($category->bracket_config) ? $category->bracket_config : [],
            $category->standingsContext(),
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  string  $context  standings shape: goal | set | rubber. Decides
     *                           which tiebreakers exist and what the points
     *                           default to when the event never set them.
     */
    public static function fromArray(array $raw, string $context = 'goal'): self
    {
        $points = is_array($raw['points'] ?? null) ? $raw['points'] : [];
        $qual = is_array($raw['qualification'] ?? null) ? $raw['qualification'] : [];

        $known = Catalog::tiebreakerKeys($context);
        $drawMethods = Catalog::keys('draw_method');
        $rounds = array_keys(Catalog::roundSizes());

        // Say the stored order in the words this context can compute, keeping
        // the event's priorities: a football event that later changed sport
        // ranks on "Selisih Game" where it used to rank on "Selisih Gol". What
        // has no equivalent here (fair play, with no cards to count) is dropped.
        $tiebreakers = Tiebreakers::remap(
            is_array($raw['tiebreakers'] ?? null) ? $raw['tiebreakers'] : $known,
            $context,
        );

        $homeAway = (bool) ($raw['home_away'] ?? false);

        // A singles/doubles match cannot end level — there is no draw to award
        // a point for — so the default there is a plain 1 per win. Squad ties
        // *can* finish 1-1, so they keep football's 3/1/0.
        $defaults = $context === 'set'
            ? ['win' => 1, 'draw' => 0, 'lose' => 0]
            : ['win' => 3, 'draw' => 1, 'lose' => 0];

        // Nothing here can earn a draw point, so a stored one was never chosen
        // for this category — it rode in from another sport's defaults, and so
        // did the 3 beside it. Drop the trio rather than leave a badminton table
        // paying 3 per win. An organizer who deliberately set 2/0/0 keeps it:
        // the tell is the impossible value, not a win worth anything but 1.
        if ($context === 'set' && (int) ($points['draw'] ?? 0) > 0) {
            $points = [];
        }

        return new self(
            groups: max(1, (int) ($raw['groups'] ?? 4)),
            teamsPerGroup: max(2, (int) ($raw['teams_per_group'] ?? 4)),
            homeAway: $homeAway,
            // Home & away implies two legs; an explicit `legs` still wins.
            legs: max(1, min(2, (int) ($raw['legs'] ?? ($homeAway ? 2 : 1)))),
            pointsWin: (int) ($points['win'] ?? $defaults['win']),
            pointsDraw: (int) ($points['draw'] ?? $defaults['draw']),
            pointsLose: (int) ($points['lose'] ?? $defaults['lose']),
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
            thirdPlace: (bool) ($raw['third_place'] ?? false),
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
            'bracket_config.third_place' => ['nullable', 'boolean'],
            'bracket_config.tiebreakers' => ['nullable', 'array'],
            'bracket_config.tiebreakers.*' => ['in:'.implode(',', Catalog::keys('tiebreaker'))],
        ];
    }
}
