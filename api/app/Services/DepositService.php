<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * Security deposit monitoring: how much of each team's jaminan is left.
 *
 * Flat per event — one amount and two deduction rates, set once in
 * `events.rules_config['deposit']`, applied the same to every team. Unlike
 * DisciplineRules there is no sport/platform layer to fall back to: an empty
 * config just means the feature is off for this event, not "inherit".
 *
 * Everything here is derived on every read, the same reasoning as
 * DisciplineService: MatchController::saveMatchStats() deletes and recreates
 * every stat row of a match, so a stored balance could outlive the card that
 * produced it. There is nothing to correct after the fact because nothing is
 * ever written.
 */
class DepositService
{
    /**
     * @return array{enabled: bool, amount: int, yellow_deduction: int, red_deduction: int, teams: array<int, array<string, mixed>>}
     */
    public function forEvent(Event $event): array
    {
        $config = $event->rules_config['deposit'] ?? [];
        $amount = (int) ($config['amount'] ?? 0);
        $yellowDeduction = (int) ($config['yellow_deduction'] ?? 0);
        $redDeduction = (int) ($config['red_deduction'] ?? 0);

        if ($amount <= 0) {
            return [
                'enabled' => false,
                'amount' => 0,
                'yellow_deduction' => 0,
                'red_deduction' => 0,
                'teams' => [],
            ];
        }

        $cards = $this->cardsByTeam($event);

        $teams = $event->teams()
            ->with('category:id,name')
            ->get(['id', 'name', 'category_id'])
            ->map(function ($team) use ($cards, $amount, $yellowDeduction, $redDeduction) {
                $yellow = $cards[$team->id]['yellow'] ?? 0;
                $red = $cards[$team->id]['red'] ?? 0;
                $deduction = $yellow * $yellowDeduction + $red * $redDeduction;

                return [
                    'team_id' => $team->id,
                    'team_name' => $team->name,
                    'category_id' => $team->category_id,
                    'category_name' => $team->category?->name,
                    'yellow_count' => $yellow,
                    'red_count' => $red,
                    'deduction' => $deduction,
                    'balance' => $amount - $deduction,
                ];
            })
            ->sortBy('team_name')
            ->values()
            ->all();

        return [
            'enabled' => true,
            'amount' => $amount,
            'yellow_deduction' => $yellowDeduction,
            'red_deduction' => $redDeduction,
            'teams' => $teams,
        ];
    }

    /**
     * Total yellow/red cards per team, across every category of the event.
     * A sport with no card stats returns an empty map, so every team simply
     * carries its full deposit — the deposit still applies, it just never
     * gets touched.
     *
     * **Bench cards are deducted too.** The jaminan answers for the conduct of
     * the people a team brings, and the coach sent off is exactly the incident an
     * organizer holds a deposit against; a deduction that skipped them would be
     * a rule nobody could read off the screen. Same reasoning as
     * StandingService::fairPlayPoints(), and the same two-table fold — test it by
     * *comparing* a player's card with an official's, not by asserting one.
     *
     * @return array<string, array{yellow: int, red: int}>
     */
    private function cardsByTeam(Event $event): array
    {
        $yellowKey = Catalog::statKeyForRole($event->sport_type, 'yellow');
        $redKey = Catalog::statKeyForRole($event->sport_type, 'red');
        $keys = array_values(array_filter([$yellowKey, $redKey]));

        if ($keys === []) {
            return [];
        }

        $cards = [];

        foreach (['player_match_stats', 'official_match_stats'] as $table) {
            $rows = DB::table($table)
                ->join('matches', 'matches.id', '=', $table.'.match_id')
                ->where('matches.event_id', $event->id)
                ->where('matches.status', 'finished')
                ->whereNotNull('matches.confirmed_at')
                ->whereIn($table.'.stat_key', $keys)
                ->select(
                    // The snapshot on the stat row, not the roster row's own
                    // team: it records which side they were with when the card
                    // was shown.
                    $table.'.team_id',
                    $table.'.stat_key',
                    $table.'.value',
                )
                ->get();

            foreach ($rows as $row) {
                $slot = $row->stat_key === $redKey ? 'red' : 'yellow';
                $cards[$row->team_id] ??= ['yellow' => 0, 'red' => 0];
                $cards[$row->team_id][$slot] += (int) $row->value;
            }
        }

        return $cards;
    }
}
