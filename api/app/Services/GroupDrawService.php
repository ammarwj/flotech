<?php

namespace App\Services;

use App\Models\EventCategory;
use App\Models\Team;
use App\Support\HybridConfig;
use Illuminate\Support\Collection;

/**
 * Assigns approved teams to groups (A, B, C, …) for a hybrid category.
 *
 * Three ways to draw:
 *  - random: shuffle, then deal round-robin into the groups
 *  - pot:    deal pot by pot, one team per group per pot (UCL-style), so the
 *            seeds spread out instead of clustering
 *  - manual: the organizer picks the group for every team
 */
class GroupDrawService
{
    /**
     * @param  array<string, string>  $assignments  team_id => group name (manual)
     * @param  array<string, int>  $pots  team_id => pot number (pot draw)
     * @return Collection<int, Team> the drawn teams, ordered by group then name
     */
    public function draw(EventCategory $category, string $method, array $assignments = [], array $pots = []): Collection
    {
        $config = HybridConfig::fromCategory($category);
        $groups = $config->groupNames();

        $teams = $category->teams()
            ->where('status', 'approved')
            ->orderBy('name')
            ->get();

        if ($teams->isEmpty()) {
            return $teams;
        }

        // A pot draw needs the pot of every team; the payload wins over what's stored.
        if ($method === 'pot' && $pots !== []) {
            foreach ($teams as $team) {
                $team->seed_pot = $pots[$team->id] ?? $team->seed_pot;
            }
        }

        $byGroup = match ($method) {
            'manual' => $this->manual($teams, $groups, $assignments),
            'pot' => $this->byPot($teams, $groups),
            default => $this->random($teams, $groups),
        };

        foreach ($byGroup as $group => $members) {
            foreach ($members as $team) {
                $team->group_name = $group;
                $team->save();
            }
        }

        return $category->teams()
            ->where('status', 'approved')
            ->orderBy('group_name')
            ->orderBy('name')
            ->get();
    }

    /**
     * Deal teams one at a time into each group in turn, so group sizes never
     * differ by more than one.
     *
     * @param  Collection<int, Team>  $teams
     * @param  array<int, string>  $groups
     * @return array<string, array<int, Team>>
     */
    protected function random(Collection $teams, array $groups): array
    {
        $out = array_fill_keys($groups, []);

        foreach ($teams->shuffle()->values() as $i => $team) {
            $out[$groups[$i % count($groups)]][] = $team;
        }

        return $out;
    }

    /**
     * Deal pot by pot: every group takes one team from pot 1, then one from pot
     * 2, and so on. Teams without a pot are treated as the last pot.
     *
     * @param  Collection<int, Team>  $teams
     * @param  array<int, string>  $groups
     * @return array<string, array<int, Team>>
     */
    protected function byPot(Collection $teams, array $groups): array
    {
        $out = array_fill_keys($groups, []);
        $lastPot = (int) ($teams->max('seed_pot') ?: 1) + 1;

        $pots = $teams
            ->groupBy(fn (Team $t) => $t->seed_pot ?: $lastPot)
            ->sortKeys();

        $i = 0;
        foreach ($pots as $pot) {
            foreach ($pot->shuffle()->values() as $team) {
                $out[$groups[$i % count($groups)]][] = $team;
                $i++;
            }
        }

        return $out;
    }

    /**
     * Honour the organizer's picks; anything left unassigned falls back into
     * the emptiest group so nobody is dropped from the draw.
     *
     * @param  Collection<int, Team>  $teams
     * @param  array<int, string>  $groups
     * @param  array<string, string>  $assignments
     * @return array<string, array<int, Team>>
     */
    protected function manual(Collection $teams, array $groups, array $assignments): array
    {
        $out = array_fill_keys($groups, []);

        $unassigned = [];
        foreach ($teams as $team) {
            $group = $assignments[$team->id] ?? null;
            if ($group !== null && in_array($group, $groups, true)) {
                $out[$group][] = $team;
            } else {
                $unassigned[] = $team;
            }
        }

        foreach ($unassigned as $team) {
            $smallest = $groups[0];
            foreach ($groups as $g) {
                if (count($out[$g]) < count($out[$smallest])) {
                    $smallest = $g;
                }
            }
            $out[$smallest][] = $team;
        }

        return $out;
    }
}
