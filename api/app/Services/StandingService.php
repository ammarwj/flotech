<?php

namespace App\Services;

use App\Models\EventCategory;
use App\Models\GameMatch;
use App\Support\HybridConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes the table for a category from its confirmed results.
 *
 * Points and tiebreakers come from the category's format config (`bracket_config`),
 * defaulting to 3/1/0 and the usual head-to-head → goal difference → goals
 * scored → fair play → drawing lots order. Hybrid categories are ranked inside
 * each group; every other format is one table.
 */
class StandingService
{
    /**
     * All approved teams, ranked. Rows carry `group_name` so the client can
     * split a hybrid category into one table per group.
     *
     * @return array<int, array<string, mixed>>
     */
    public function compute(EventCategory $category): array
    {
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
     * @return array<int, array{label: string, group: string|null, place: int, team: array<string, mixed>|null}>
     */
    public function qualifierSlots(EventCategory $category): array
    {
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
                    'label' => $this->placeLabel($place)." Grup {$group}",
                    'group' => $group,
                    'place' => $place,
                    'team' => $ranked[$group][$place - 1]['team'] ?? null,
                ];
            }
        }

        // Extra places: the best teams from the first non-qualifying rank.
        $extras = [
            2 => ['take' => $config->bestRunnersUp, 'label' => 'Best Runner-up'],
            3 => ['take' => $config->bestThirds, 'label' => 'Best Third Place'],
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
     * so it is skipped here.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function crossGroupOrder(array $rows, EventCategory $category, HybridConfig $config): array
    {
        $tiebreakers = array_values(array_diff($config->tiebreakers, ['head_to_head']));

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
                'goals_for' => 0,
                'goals_against' => 0,
                'goal_diff' => 0,
                'points' => 0,
                'fair_play' => 0,
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

        return $rows;
    }

    /**
     * Confirmed, played matches that count toward the table. The knockout stage
     * of a hybrid category never does.
     *
     * @return Collection<int, GameMatch>
     */
    protected function countingMatches(EventCategory $category): Collection
    {
        return $category->matches()
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
     * Sort rows by points, then by each configured tiebreaker in turn, and stamp
     * the resulting rank.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>|null  $tiebreakers  overrides the category config
     * @return array<int, array<string, mixed>>
     */
    protected function rank(array $rows, EventCategory $category, HybridConfig $config, ?array $tiebreakers = null): array
    {
        $order = $tiebreakers ?? $config->tiebreakers;
        $h2h = in_array('head_to_head', $order, true) ? $this->headToHead($category) : [];

        usort($rows, function ($a, $b) use ($order, $h2h, $category) {
            if ($a['points'] !== $b['points']) {
                return $b['points'] <=> $a['points'];
            }

            foreach ($order as $rule) {
                $cmp = $this->compareBy($rule, $a, $b, $h2h, $category);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return strcmp($a['team']['name'], $b['team']['name']);
        });

        foreach ($rows as $i => &$row) {
            $row['rank'] = $i + 1;
        }

        return $rows;
    }

    /**
     * One tiebreaker, applied to a pair of tied teams. Returns <0 when $a ranks
     * ahead of $b.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  array<string, array<string, array{points: int, diff: int}>>  $h2h
     */
    protected function compareBy(string $rule, array $a, array $b, array $h2h, EventCategory $category): int
    {
        $idA = $a['team']['id'];
        $idB = $b['team']['id'];

        return match ($rule) {
            // Only the matches the two played against each other: points, then
            // goal difference across those meetings.
            'head_to_head' => [
                $h2h[$idB][$idA]['points'] ?? 0,
                $h2h[$idB][$idA]['diff'] ?? 0,
            ] <=> [
                $h2h[$idA][$idB]['points'] ?? 0,
                $h2h[$idA][$idB]['diff'] ?? 0,
            ],
            'goal_difference' => $b['goal_diff'] <=> $a['goal_diff'],
            'goals_scored' => $b['goals_for'] <=> $a['goals_for'],
            // Fewer disciplinary points ranks higher.
            'fair_play' => $a['fair_play'] <=> $b['fair_play'],
            // A stable "draw": random-looking but the same every time it's shown.
            'drawing_lots' => $this->lot($category, $idA) <=> $this->lot($category, $idB),
            default => 0,
        };
    }

    /**
     * Points and goal difference each team took off each other.
     *
     * @return array<string, array<string, array{points: int, diff: int}>>
     */
    protected function headToHead(EventCategory $category): array
    {
        $config = HybridConfig::fromCategory($category);
        $out = [];

        foreach ($this->countingMatches($category) as $m) {
            $home = $m->home_team_id;
            $away = $m->away_team_id;
            if (! $home || ! $away) {
                continue;
            }

            $diff = $m->home_score - $m->away_score;
            $homePoints = $diff > 0 ? $config->pointsWin : ($diff === 0 ? $config->pointsDraw : $config->pointsLose);
            $awayPoints = $diff < 0 ? $config->pointsWin : ($diff === 0 ? $config->pointsDraw : $config->pointsLose);

            $out[$home][$away]['points'] = ($out[$home][$away]['points'] ?? 0) + $homePoints;
            $out[$home][$away]['diff'] = ($out[$home][$away]['diff'] ?? 0) + $diff;
            $out[$away][$home]['points'] = ($out[$away][$home]['points'] ?? 0) + $awayPoints;
            $out[$away][$home]['diff'] = ($out[$away][$home]['diff'] ?? 0) - $diff;
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
