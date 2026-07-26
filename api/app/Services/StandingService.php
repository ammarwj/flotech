<?php

namespace App\Services;

use App\Models\EventCategory;
use App\Models\GameMatch;
use App\Support\HybridConfig;
use App\Support\MatchScoring;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes the table for a category from its confirmed results.
 *
 * Points and tiebreakers come from the category's format config
 * (`bracket_config`), whose defaults follow the standings context — football
 * gets 3/1/0 and head-to-head → goal difference → goals scored, badminton gets
 * 1/0 and head-to-head → selisih game → selisih skor. Hybrid categories are
 * ranked inside each group; every other format is one table.
 *
 * Every row carries three tiers of for/against, and the context says what they
 * are called: `goals_*` is the match score (gol, game menang, or partai
 * menang), `sets_*` the games behind a squad tie, `points_*` the raw points
 * behind the sets. A tier a context has no use for stays zero.
 *
 * A row also says whether its place is real: `needs_decider` means nothing in
 * the config could separate it from the others it is level with, so what put it
 * there is the lot, and they are owed a decider — the extra tie in `deciders()`.
 *
 * Teams that are level are ranked as a *group*, not by comparing them two at a
 * time, because head to head is a table among them — see miniLeague() for the
 * three-team circle that makes the difference.
 */
class StandingService
{
    /**
     * The fixtures behind the table being computed right now — see
     * countingMatches(), and forget() for how long "right now" lasts.
     *
     * @var array<string, Collection<int, GameMatch>> category id => countingMatches()
     */
    protected array $matchCache = [];

    /**
     * Drop the memoized fixtures. Called at the top of every public entry
     * point, because the service can outlive the results it read: one instance
     * answers the knockout plan both before a ball is kicked and after the
     * groups are done, and a table built from the first read would have every
     * team on nought points forever.
     *
     * The cache is worth having anyway — a hybrid category reads the same
     * fixtures once per group and again per tied block inside it — it just has
     * to be scoped to a single computation rather than to the object.
     */
    protected function forget(): void
    {
        $this->matchCache = [];
    }

    /**
     * All approved teams, ranked. Rows carry `group_name` so the client can
     * split a hybrid category into one table per group.
     *
     * @return array<int, array<string, mixed>>
     */
    public function compute(EventCategory $category): array
    {
        $this->forget();

        $config = HybridConfig::fromCategory($category);
        $rows = $this->rows($category, $config);

        if ($category->engine() !== 'hybrid') {
            return $this->rank(array_values($rows), $category, $config);
        }

        // Rank inside each group, then concatenate A, B, C, …
        $out = [];
        foreach ($this->byGroup($rows) as $groupRows) {
            foreach ($this->rank($groupRows, $category, $config) as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * The seeds of the knockout stage, best first — as *slots* rather than
     * teams, so the bracket is known before a ball is kicked: "Juara Grup A",
     * "Runner-up Grup B", "Best Third Place 1".
     *
     * Each slot carries whoever currently holds that place (null while the group
     * has no results), which is what lets the bracket be previewed and then fill
     * itself in as the group stage plays out.
     *
     * Automatic places are seeded by group letter (all winners A→D, then all
     * runners-up A→D), so the pairings are predictable up front. Only the extra
     * places — best runners-up / best thirds — are ranked across groups, because
     * that is what those places mean.
     *
     * Every slot also carries a stable `key` — "A1", "B2", "BR1" — which is what
     * a saved knockout plan pairs up. It has to survive a reshuffle of the list
     * (a changed `best_runners_up` moves the extras around), so it is derived
     * from what the slot *is*, never from its position.
     *
     * @return array<int, array{key: string, label: string, group: string|null, place: int, team: array<string, mixed>|null}>
     */
    public function qualifierSlots(EventCategory $category): array
    {
        $this->forget();

        $config = HybridConfig::fromCategory($category);
        $groups = $this->byGroup($this->rows($category, $config));

        // group => rows, ranked
        $ranked = [];
        foreach ($groups as $name => $groupRows) {
            $ranked[$name] = $this->rank($groupRows, $category, $config);
        }

        $slots = [];

        for ($place = 1; $place <= $config->topPerGroup; $place++) {
            foreach ($config->groupNames() as $group) {
                $slots[] = [
                    'key' => $group.$place,
                    'label' => $this->placeLabel($place)." Grup {$group}",
                    'group' => $group,
                    'place' => $place,
                    'team' => $ranked[$group][$place - 1]['team'] ?? null,
                ];
            }
        }

        // Extra places: the best teams from the first non-qualifying rank.
        $extras = [
            2 => ['take' => $config->bestRunnersUp, 'label' => 'Best Runner-up', 'key' => 'BR'],
            3 => ['take' => $config->bestThirds, 'label' => 'Best Third Place', 'key' => 'BT'],
        ];

        foreach ($extras as $place => $extra) {
            if ($extra['take'] < 1 || $place <= $config->topPerGroup) {
                continue; // that place already qualifies automatically
            }

            $pool = [];
            foreach ($ranked as $rows) {
                if (isset($rows[$place - 1])) {
                    $pool[] = $rows[$place - 1];
                }
            }
            $best = array_slice($this->crossGroupOrder($pool, $category, $config), 0, $extra['take']);

            for ($i = 0; $i < $extra['take']; $i++) {
                $row = $best[$i] ?? null;
                $slots[] = [
                    'key' => $extra['key'].($i + 1),
                    'label' => $extra['label'].' '.($i + 1),
                    'group' => null, // could come from any group — no clash to avoid
                    'place' => $place,
                    'team' => $row['team'] ?? null,
                ];
            }
        }

        return $slots;
    }

    /**
     * The teams that qualify for the knockout stage, best seed first.
     *
     * @return array<int, string> team ids
     */
    public function qualifiers(EventCategory $category): array
    {
        return array_values(array_filter(array_map(
            fn ($slot) => $slot['team']['id'] ?? null,
            $this->qualifierSlots($category),
        )));
    }

    protected function placeLabel(int $place): string
    {
        return match ($place) {
            1 => 'Juara',
            2 => 'Runner-up',
            default => "Peringkat {$place}",
        };
    }

    /**
     * Rank teams that finished in the same group position against each other
     * (the "best runner-up" table). Head-to-head is meaningless across groups,
     * so it is skipped here — and so is the decider, which is only ever played
     * between two teams of the same group.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function crossGroupOrder(array $rows, EventCategory $category, HybridConfig $config): array
    {
        $tiebreakers = array_values(array_filter(
            $config->tiebreakers,
            fn ($rule) => ! in_array(Catalog::comparatorOf($rule), ['head_to_head', 'playoff'], true),
        ));

        return $this->rank($rows, $category, $config, $tiebreakers);
    }

    /**
     * One row per approved team, filled in from the confirmed results.
     *
     * @return array<string, array<string, mixed>> keyed by team id
     */
    protected function rows(EventCategory $category, HybridConfig $config): array
    {
        $teams = $category->teams()
            ->where('status', 'approved')
            ->orderBy('name')
            ->get(['id', 'name', 'logo_url', 'group_name']);

        $rows = [];
        foreach ($teams as $team) {
            $rows[$team->id] = [
                'team' => ['id' => $team->id, 'name' => $team->name, 'logo_url' => $team->logo_url],
                'group_name' => $team->group_name,
                'played' => 0,
                'won' => 0,
                'drawn' => 0,
                'lost' => 0,
                // The match score, whatever the sport calls it: gol, game
                // menang (racket singles/doubles), or partai menang (squad tie).
                'goals_for' => 0,
                'goals_against' => 0,
                'goal_diff' => 0,
                // Games won behind a squad tie — the tier between its partai and
                // its raw points. Zero elsewhere: for a singles category the
                // games *are* the match score above.
                'sets_for' => 0,
                'sets_against' => 0,
                'set_diff' => 0,
                // Raw points across every set played. Two entrants can split
                // their games (or, for a squad, their ties) and this is what
                // separates them.
                'points_for' => 0,
                'points_against' => 0,
                'points_diff' => 0,
                'points' => 0,
                'fair_play' => 0,
                // Nothing in the config could separate this team from the one
                // beside it, so its place is currently the lot's guess. Stamped
                // by rank(), which is the only thing that knows the pair.
                'needs_decider' => false,
            ];
        }

        foreach ($this->countingMatches($category) as $m) {
            if (! isset($rows[$m->home_team_id], $rows[$m->away_team_id])) {
                continue;
            }

            $this->applyResult($rows[$m->home_team_id], $m->home_score, $m->away_score, $config);
            $this->applyResult($rows[$m->away_team_id], $m->away_score, $m->home_score, $config);
        }

        foreach ($this->fairPlayPoints($category) as $teamId => $points) {
            if (isset($rows[$teamId])) {
                $rows[$teamId]['fair_play'] = $points;
            }
        }

        foreach ($this->scoreDetail($category) as $teamId => $totals) {
            if (isset($rows[$teamId])) {
                $rows[$teamId] = [...$rows[$teamId], ...$totals];
            }
        }

        foreach ($rows as &$row) {
            $row['set_diff'] = $row['sets_for'] - $row['sets_against'];
            $row['points_diff'] = $row['points_for'] - $row['points_against'];
        }

        return $rows;
    }

    /**
     * The tiers below the match score: games won, and the points behind them.
     *
     * Read straight off the sets rather than from the scoreline, since "3-0"
     * says nothing about how close the games were — which is the entire point
     * of the tiebreakers built on this.
     *
     * A goal sport has neither tier, and a singles/doubles category has no
     * separate games tier: its match score already *is* the games won.
     *
     * @return array<string, array{sets_for?: int, sets_against?: int, points_for: int, points_against: int}>
     *                                                                                                        team id => totals
     */
    protected function scoreDetail(EventCategory $category): array
    {
        $context = $category->standingsContext();

        if ($context === 'goal') {
            return [];
        }

        $rubbers = $context === 'rubber';
        $matches = $this->countingMatches($category);

        if ($rubbers) {
            $matches->load('rubbers');
        }

        $out = [];

        foreach ($matches as $match) {
            $points = $rubbers
                ? MatchScoring::rubberPoints($match->rubbers)
                : MatchScoring::setPoints($match->sets ?? []);
            $sets = $rubbers
                ? MatchScoring::rubberSets($match->rubbers)
                : ['home' => 0, 'away' => 0];

            foreach (['home' => 'away', 'away' => 'home'] as $side => $other) {
                $teamId = $match->{"{$side}_team_id"};

                $out[$teamId]['points_for'] = ($out[$teamId]['points_for'] ?? 0) + $points[$side];
                $out[$teamId]['points_against'] = ($out[$teamId]['points_against'] ?? 0) + $points[$other];
                $out[$teamId]['sets_for'] = ($out[$teamId]['sets_for'] ?? 0) + $sets[$side];
                $out[$teamId]['sets_against'] = ($out[$teamId]['sets_against'] ?? 0) + $sets[$other];
            }
        }

        return $out;
    }

    /**
     * Confirmed, played matches that count toward the table. The knockout stage
     * of a hybrid category never does.
     *
     * Memoized because a hybrid category asks for these once per group and then
     * once more per tied block inside it — sixteen groups is a lot of times to
     * read the same fixtures. The memo is cleared by forget() at the start of
     * every public entry point, which is what keeps it from answering with the
     * fixtures of an earlier, emptier moment.
     *
     * @return Collection<int, GameMatch>
     */
    protected function countingMatches(EventCategory $category): Collection
    {
        return $this->matchCache[$category->id] ??= $category->matches()
            ->where('status', 'finished')
            ->whereNotNull('confirmed_at')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->where(fn ($q) => $q->whereNull('stage')->orWhere('stage', 'group'))
            ->get();
    }

    /**
     * Disciplinary points per team: 1 per yellow card, 3 per red. Lower is
     * better, which is exactly how the fair-play tiebreaker reads it.
     *
     * Scoped to the same matches the table itself counts. Cards shown in a
     * decider — the extra tie played *because* fair play could not separate two
     * teams — would otherwise feed back into the criterion above it and reorder
     * the pair before the decider's own result is even read. The knockout stage
     * leaks the same way, and never belonged in a group table either.
     *
     * @return array<string, int> team id => points
     */
    protected function fairPlayPoints(EventCategory $category): array
    {
        // Which stats count as misconduct, and how heavily, is per-sport data
        // (football: yellow 1, red 3). A sport with no weighted stat simply has
        // no fair-play score.
        $weights = Catalog::fairPlayWeights($category->sport_type);

        if ($weights === []) {
            return [];
        }

        $rows = DB::table('player_match_stats')
            ->join('matches', 'matches.id', '=', 'player_match_stats.match_id')
            ->where('matches.category_id', $category->id)
            ->where(fn ($q) => $q->whereNull('matches.stage')->orWhere('matches.stage', 'group'))
            ->whereIn('player_match_stats.stat_key', array_keys($weights))
            ->groupBy('player_match_stats.team_id', 'player_match_stats.stat_key')
            ->select(
                'player_match_stats.team_id',
                'player_match_stats.stat_key',
                DB::raw('SUM(player_match_stats.value) as total'),
            )
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->team_id] = ($out[$r->team_id] ?? 0) + $weights[$r->stat_key] * (int) $r->total;
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<int, array<string, mixed>>> group name => rows
     */
    protected function byGroup(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['group_name'] ?? '-'][] = $row;
        }
        ksort($out);

        return $out;
    }

    /**
     * Sort rows by points, then settle each set of level teams against the
     * configured tiebreakers, and stamp the resulting rank.
     *
     * Points come first and no config reorders that. Everything below is
     * resolved a *block* at a time rather than by one comparator over the whole
     * table, because head to head is a table among the teams that are level and
     * therefore has to know who they are — see miniLeague().
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>|null  $tiebreakers  overrides the category config
     * @return array<int, array<string, mixed>>
     */
    protected function rank(array $rows, EventCategory $category, HybridConfig $config, ?array $tiebreakers = null): array
    {
        $order = $tiebreakers ?? $config->tiebreakers;
        $deciders = $this->usesComparator($order, 'playoff') ? $this->deciders($category) : [];

        // Name is not a tiebreaker here, only what keeps this pass from
        // depending on the order the rows arrived in: everything level on
        // points is handed to resolveBlock() as one block regardless.
        usort($rows, fn ($a, $b) => [$b['points'], $a['team']['name']] <=> [$a['points'], $b['team']['name']]);

        $out = [];
        foreach ($this->blocks($rows, fn ($a, $b) => $b['points'] <=> $a['points']) as $block) {
            foreach ($this->resolveBlock($block, $order, $config, $deciders, $category, 0) as $row) {
                $out[] = $row;
            }
        }

        foreach ($out as $i => &$row) {
            $row['rank'] = $i + 1;
        }
        unset($row);

        return $out;
    }

    /**
     * Order a set of teams nothing above the tiebreakers could separate.
     *
     * Walks the configured order until a rule splits the block, then hands each
     * remaining group back to itself *from the top of the order again*. That
     * restart is the point of the recursion, not tidiness: two teams left level
     * after a head to head among three are owed a head to head among two, which
     * is a different table over different matches. Jumping straight to the next
     * criterion would rank them on goal difference while the match they played
     * against each other sits unread.
     *
     * It terminates because a block only recurses when a rule split it, so
     * every sub-block is strictly smaller than the one it came from.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $order
     * @param  array<string, array<string, int>>  $deciders
     * @param  int  $from  index into $order to resume at
     * @return array<int, array<string, mixed>>
     */
    protected function resolveBlock(array $rows, array $order, HybridConfig $config, array $deciders, EventCategory $category, int $from): array
    {
        if (count($rows) < 2) {
            return $rows;
        }

        for ($i = $from; $i < count($order); $i++) {
            $rule = $order[$i];
            $comparator = Catalog::comparatorOf($rule);

            // Reaching the lot means every rule that answers to a result has
            // already been asked and none of them answered: this block is where
            // it is because of a draw, and is owed a decider. Said before the
            // lot is applied, since the lot itself always separates them.
            if ($comparator === 'drawing_lots') {
                $this->markUndecided($rows, $order);
            }

            $mini = $comparator === 'head_to_head'
                ? $this->miniLeague($category, $config, array_column(array_column($rows, 'team'), 'id'))
                : [];

            $compare = fn ($a, $b) => $this->compareBy($rule, $a, $b, $mini, $deciders, $category);

            usort($rows, fn ($a, $b) => $compare($a, $b) ?: strcmp($a['team']['name'], $b['team']['name']));

            $blocks = $this->blocks($rows, $compare);

            if (count($blocks) === 1) {
                continue; // this rule separated nobody — ask the next one
            }

            $out = [];
            foreach ($blocks as $block) {
                foreach ($this->resolveBlock($block, $order, $config, $deciders, $category, 0) as $row) {
                    $out[] = $row;
                }
            }

            return $out;
        }

        // The order ran out with these still level — it holds no lot to break
        // the tie with either, so the name is all that is left.
        $this->markUndecided($rows, $order);
        usort($rows, fn ($a, $b) => strcmp($a['team']['name'], $b['team']['name']));

        return $rows;
    }

    /**
     * Split an already-sorted list into runs of adjacent rows the comparator
     * cannot tell apart.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>, array<string, mixed>): int  $compare
     * @return array<int, array<int, array<string, mixed>>>
     */
    protected function blocks(array $rows, callable $compare): array
    {
        $out = [];
        $run = [];

        foreach ($rows as $row) {
            if ($run !== [] && $compare($run[count($run) - 1], $row) !== 0) {
                $out[] = $run;
                $run = [];
            }
            $run[] = $row;
        }

        if ($run !== []) {
            $out[] = $run;
        }

        return $out;
    }

    /**
     * Mark a block whose order is, right now, whatever the lot said.
     *
     * Only raised when the order actually holds the decider rule, because the
     * mark is an invitation to play one: telling an organizer who removed it
     * that a tie is unresolved would point at a fixture that changes nothing.
     *
     * @param  array<int, array<string, mixed>>  $rows  by reference
     * @param  array<int, string>  $order
     */
    protected function markUndecided(array &$rows, array $order): void
    {
        if (! $this->usesComparator($order, 'playoff')) {
            return;
        }

        foreach ($rows as &$row) {
            $row['needs_decider'] = true;
        }
        unset($row);
    }

    /**
     * Whether an order runs a comparator at all. Asked of the comparator rather
     * than the key because one comparator wears several: the decider tie is
     * "Adu Penalti" in football and "Laga Penentuan" in badminton.
     *
     * @param  array<int, string>  $order
     */
    protected function usesComparator(array $order, string $comparator): bool
    {
        foreach ($order as $rule) {
            if (Catalog::comparatorOf($rule) === $comparator) {
                return true;
            }
        }

        return false;
    }

    /**
     * One tiebreaker, applied to a pair of tied teams. Returns <0 when $a ranks
     * ahead of $b.
     *
     * `$rule` is the configured tiebreaker *key*, which is a preset over one of
     * these comparators — "Selisih Gol" and "Selisih Game" both compare the
     * match score, they only differ in what the sport calls it.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  array<string, array{points: int, diff: int, scored: int}>  $mini  the tied block's table, from miniLeague()
     * @param  array<string, array<string, int>>  $deciders
     */
    protected function compareBy(string $rule, array $a, array $b, array $mini, array $deciders, EventCategory $category): int
    {
        $idA = $a['team']['id'];
        $idB = $b['team']['id'];

        return match (Catalog::comparatorOf($rule)) {
            // Their place in the table they make among themselves — points,
            // then goal difference, then goals scored across those meetings.
            'head_to_head' => [
                $mini[$idB]['points'] ?? 0,
                $mini[$idB]['diff'] ?? 0,
                $mini[$idB]['scored'] ?? 0,
            ] <=> [
                $mini[$idA]['points'] ?? 0,
                $mini[$idA]['diff'] ?? 0,
                $mini[$idA]['scored'] ?? 0,
            ],
            'goal_difference' => $b['goal_diff'] <=> $a['goal_diff'],
            'goals_scored' => $b['goals_for'] <=> $a['goals_for'],
            // Squad ties only: the games won behind the partai count.
            'set_difference' => $b['set_diff'] <=> $a['set_diff'],
            // The aggregate points behind the sets. `rubber_points` is the name
            // this comparator carried while squad ties were its only user.
            'point_difference', 'rubber_points' => $b['points_diff'] <=> $a['points_diff'],
            // Fewer disciplinary points ranks higher.
            'fair_play' => $a['fair_play'] <=> $b['fair_play'],
            // The extra tie the two played to settle exactly this. Silent until
            // one has been played and confirmed, which is what leaves the pair
            // on the lot below in the meantime.
            'playoff' => ($deciders[$idB][$idA] ?? 0) <=> ($deciders[$idA][$idB] ?? 0),
            // A stable "draw": random-looking but the same every time it's shown.
            'drawing_lots' => $this->lot($category, $idA) <=> $this->lot($category, $idB),
            default => 0,
        };
    }

    /**
     * The table a set of tied teams makes among themselves: points, goal
     * difference and goals scored across the matches they played against *each
     * other*, and nothing else.
     *
     * This is what head to head means, and why it cannot be a comparison of two
     * teams in isolation. Three teams that beat each other in a circle separate
     * pairwise perfectly — each of the three comparisons is decisive — but the
     * three answers contradict, so the order becomes whatever the sort happened
     * to walk them in, and the criterion listed *below* head to head is never
     * reached to settle it. Read as a table, the circle is what it plainly is:
     * three teams level on points, falling through to the next criterion.
     *
     * The tied set is the input rather than something derived here, because it
     * is the caller's block that defines it: two teams left over from a tie of
     * three are a new, smaller head to head, over a different set of matches.
     *
     * @param  array<int, string>  $teamIds  the tied teams
     * @return array<string, array{points: int, diff: int, scored: int}>
     */
    protected function miniLeague(EventCategory $category, HybridConfig $config, array $teamIds): array
    {
        $tied = array_flip($teamIds);
        $out = array_fill_keys($teamIds, ['points' => 0, 'diff' => 0, 'scored' => 0]);

        foreach ($this->countingMatches($category) as $m) {
            $home = $m->home_team_id;
            $away = $m->away_team_id;

            // Both ends inside the tied set, or the match says nothing about it.
            if (! $home || ! $away || ! isset($tied[$home], $tied[$away])) {
                continue;
            }

            $diff = $m->home_score - $m->away_score;

            $out[$home]['points'] += $diff > 0 ? $config->pointsWin : ($diff === 0 ? $config->pointsDraw : $config->pointsLose);
            $out[$away]['points'] += $diff < 0 ? $config->pointsWin : ($diff === 0 ? $config->pointsDraw : $config->pointsLose);
            $out[$home]['diff'] += $diff;
            $out[$away]['diff'] -= $diff;
            $out[$home]['scored'] += $m->home_score;
            $out[$away]['scored'] += $m->away_score;
        }

        return $out;
    }

    /**
     * Who won the deciders — the extra ties played only to break a deadlock the
     * table could not.
     *
     * Deliberately *not* read through countingMatches(): that is the method that
     * keeps these fixtures out of the table, and it has to keep doing so. A
     * decider adds no point, no goal and no appearance to anyone; the single
     * thing it is allowed to move is the order of two rows.
     *
     * A win is recorded as a plain 1, so the shape is the same mini-league
     * head-to-head builds and three teams can be separated by playing each
     * other. Level after the scoreline, the shootout decides; level after that
     * too — a decider saved without one — the pair simply stays undecided and
     * falls through to the lot.
     *
     * @return array<string, array<string, int>> winner id => loser id => 1
     */
    protected function deciders(EventCategory $category): array
    {
        $matches = $category->matches()
            ->where('stage', 'playoff')
            ->where('status', 'finished')
            ->whereNotNull('confirmed_at')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->get();

        $out = [];

        foreach ($matches as $m) {
            $home = $m->home_team_id;
            $away = $m->away_team_id;
            if (! $home || ! $away) {
                continue;
            }

            $margin = $m->home_score <=> $m->away_score;

            if ($margin === 0) {
                $margin = ($m->home_penalty ?? 0) <=> ($m->away_penalty ?? 0);
            }

            if ($margin === 0) {
                continue;
            }

            [$winner, $loser] = $margin > 0 ? [$home, $away] : [$away, $home];
            $out[$winner][$loser] = 1;
        }

        return $out;
    }

    /** Deterministic lot for a team within a category. */
    protected function lot(EventCategory $category, string $teamId): int
    {
        return crc32($category->id.$teamId);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function applyResult(array &$row, int $for, int $against, HybridConfig $config): void
    {
        $row['played']++;
        $row['goals_for'] += $for;
        $row['goals_against'] += $against;
        $row['goal_diff'] = $row['goals_for'] - $row['goals_against'];

        if ($for > $against) {
            $row['won']++;
            $row['points'] += $config->pointsWin;
        } elseif ($for === $against) {
            $row['drawn']++;
            $row['points'] += $config->pointsDraw;
        } else {
            $row['lost']++;
            $row['points'] += $config->pointsLose;
        }
    }
}
