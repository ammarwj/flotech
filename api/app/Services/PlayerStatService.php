<?php

namespace App\Services;

use App\Models\Event;
use App\Support\SportStats;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates player statistics for an event into a sport-aware leaderboard.
 */
class PlayerStatService
{
    /**
     * @return array{columns: array<int, array{key: string, label: string, short: string}>, primary: string, rows: array<int, array<string, mixed>>}
     */
    public function leaderboard(Event $event, int $limit = 100): array
    {
        $columns = SportStats::columns($event->sport_type);
        $keys = array_column($columns, 'key');
        $primary = $keys[0];

        $aggregates = DB::table('player_match_stats')
            ->join('matches', 'matches.id', '=', 'player_match_stats.match_id')
            ->join('players', 'players.id', '=', 'player_match_stats.player_id')
            ->join('teams', 'teams.id', '=', 'player_match_stats.team_id')
            ->where('matches.event_id', $event->id)
            ->whereIn('player_match_stats.stat_key', $keys)
            ->groupBy('players.id', 'players.full_name', 'teams.id', 'teams.name', 'player_match_stats.stat_key')
            ->select(
                'players.id as player_id',
                'players.full_name as player_name',
                'teams.id as team_id',
                'teams.name as team_name',
                'player_match_stats.stat_key',
                DB::raw('SUM(player_match_stats.value) as total'),
            )
            ->get();

        // Pivot stat rows into one entry per player.
        $players = [];
        foreach ($aggregates as $r) {
            if (! isset($players[$r->player_id])) {
                $players[$r->player_id] = [
                    'player_id' => $r->player_id,
                    'player_name' => $r->player_name,
                    'team_id' => $r->team_id,
                    'team_name' => $r->team_name,
                    'stats' => array_fill_keys($keys, 0),
                ];
            }
            $players[$r->player_id]['stats'][$r->stat_key] = (int) $r->total;
        }

        $rows = array_values($players);

        usort($rows, fn ($a, $b) => [$b['stats'][$primary], $a['player_name']] <=> [$a['stats'][$primary], $b['player_name']]);

        $rows = array_slice($rows, 0, $limit);
        foreach ($rows as $i => &$row) {
            $row['rank'] = $i + 1;
        }

        return ['columns' => $columns, 'primary' => $primary, 'rows' => $rows];
    }
}
