<?php

namespace App\Services;

use App\Models\Event;

/**
 * Computes the league table for an event from its finished matches.
 * Win = 3 pts, draw = 1, loss = 0. Tiebreak: points, goal diff, goals for, name.
 */
class StandingService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function compute(Event $event): array
    {
        $teams = $event->teams()
            ->where('status', 'approved')
            ->orderBy('name')
            ->get(['id', 'name', 'city', 'logo_url']);

        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];
        foreach ($teams as $team) {
            $rows[$team->id] = [
                'team' => ['id' => $team->id, 'name' => $team->name, 'city' => $team->city, 'logo_url' => $team->logo_url],
                'played' => 0,
                'won' => 0,
                'drawn' => 0,
                'lost' => 0,
                'goals_for' => 0,
                'goals_against' => 0,
                'goal_diff' => 0,
                'points' => 0,
            ];
        }

        $matches = $event->matches()
            ->where('status', 'finished')
            ->whereNotNull('confirmed_at')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->get();

        foreach ($matches as $m) {
            if (! isset($rows[$m->home_team_id], $rows[$m->away_team_id])) {
                continue;
            }

            $this->applyResult($rows[$m->home_team_id], $m->home_score, $m->away_score);
            $this->applyResult($rows[$m->away_team_id], $m->away_score, $m->home_score);
        }

        $rows = array_values($rows);

        usort($rows, function ($a, $b) {
            return [$b['points'], $b['goal_diff'], $b['goals_for'], $a['team']['name']]
                <=> [$a['points'], $a['goal_diff'], $a['goals_for'], $b['team']['name']];
        });

        foreach ($rows as $i => &$row) {
            $row['rank'] = $i + 1;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function applyResult(array &$row, int $for, int $against): void
    {
        $row['played']++;
        $row['goals_for'] += $for;
        $row['goals_against'] += $against;
        $row['goal_diff'] = $row['goals_for'] - $row['goals_against'];

        if ($for > $against) {
            $row['won']++;
            $row['points'] += 3;
        } elseif ($for === $against) {
            $row['drawn']++;
            $row['points'] += 1;
        } else {
            $row['lost']++;
        }
    }
}
