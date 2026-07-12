<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Player;
use App\Services\ScheduleService;
use App\Support\MatchScoring;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fills in results for an event that already has a schedule, so the table,
 * bracket and leaderboard have something to show.
 *
 * Matches that already carry a result are left alone — hand-entered scores
 * survive a re-run. Knockout ties are always decisive and the winner is pushed
 * into the next round, exactly like confirming the result in the UI.
 *
 *   php artisan db:seed --class=MatchResultSeeder                  # every event with fixtures
 *   EVENT=ring-of-jogja php artisan db:seed --class=MatchResultSeeder
 */
class MatchResultSeeder extends Seeder
{
    public function run(ScheduleService $schedule): void
    {
        $slug = env('EVENT');

        $events = Event::when($slug, fn ($q) => $q->where('slug', $slug))
            ->has('matches')
            ->get();

        if ($events->isEmpty()) {
            $this->command?->warn($slug
                ? "Tidak ada event berjadwal dengan slug \"{$slug}\"."
                : 'Tidak ada event yang punya jadwal. Buat jadwal dulu.');

            return;
        }

        foreach ($events as $event) {
            $played = $this->playEvent($event, $schedule);

            $this->command?->info(
                "{$event->name}: {$played} hasil diisi ("
                .$event->matches()->where('status', 'finished')->count().'/'
                .$event->matches()->count().' selesai).'
            );
        }
    }

    /**
     * Play every unplayed fixture, round by round so knockout slots that fill in
     * along the way get played too.
     */
    private function playEvent(Event $event, ScheduleService $schedule): int
    {
        $played = 0;

        // Group stage first, then the knockout rounds it feeds. `null` is the
        // single-stage case (league, plain knockout).
        foreach (['group', null, 'knockout'] as $stage) {
            $played += $this->playStage($event, $stage, $schedule);
        }

        return $played;
    }

    private function playStage(Event $event, ?string $stage, ScheduleService $schedule): int
    {
        $knockout = $stage === 'knockout'
            || in_array($event->tournament_format, ['knockout_single', 'knockout_double'], true);

        $rounds = $event->matches()
            ->when($stage, fn ($q) => $q->where('stage', $stage), fn ($q) => $q->whereNull('stage'))
            ->distinct()
            ->orderBy('round')
            ->pluck('round');

        $played = 0;

        foreach ($rounds as $round) {
            // Re-read per round: a knockout slot is only populated once the
            // previous round has been decided.
            $matches = $event->matches()
                ->when($stage, fn ($q) => $q->where('stage', $stage), fn ($q) => $q->whereNull('stage'))
                ->where('round', $round)
                ->orderBy('order')
                ->get();

            foreach ($matches as $m) {
                // Skip byes, empty slots, and anything already scored.
                if ($m->status === 'finished' || ! $m->home_team_id || ! $m->away_team_id) {
                    continue;
                }

                $this->playMatch($m, $event->sport_type, $knockout);
                $played++;

                if ($knockout) {
                    $schedule->advanceWinner($m->fresh());
                }
            }
        }

        return $played;
    }

    private function playMatch(GameMatch $m, string $sport, bool $decisive): void
    {
        if (MatchScoring::isSetBased($sport)) {
            [$home, $away, $sets] = $this->sets($sport);
            $m->update([
                'home_score' => $home,
                'away_score' => $away,
                'sets' => $sets,
                'status' => 'finished',
                'confirmed_at' => now(),
            ]);
        } else {
            [$home, $away] = $this->goals($decisive);
            $m->update([
                'home_score' => $home,
                'away_score' => $away,
                'sets' => null,
                'status' => 'finished',
                'confirmed_at' => now(),
            ]);
        }

        $this->playerStats($m->fresh(), $sport);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function goals(bool $decisive): array
    {
        // Slight home edge, and knockout ties can't end level.
        $home = random_int(0, 4);
        $away = random_int(0, 3);

        if ($decisive && $home === $away) {
            random_int(0, 1) ? $home++ : $away++;
        }

        return [$home, $away];
    }

    /**
     * Best-of-3 (racket sports) / best-of-5 (volleyball), always decisive.
     *
     * @return array{0: int, 1: int, 2: array<int, array{home: int, away: int}>}
     */
    private function sets(string $sport): array
    {
        $needed = $sport === 'volleyball' ? 3 : 2;
        $cap = $sport === 'volleyball' ? 25 : 21;
        $homeWins = (bool) random_int(0, 1);
        $loserSets = random_int(0, $needed - 1);

        $homeSets = $homeWins ? $needed : $loserSets;
        $awaySets = $homeWins ? $loserSets : $needed;

        $sets = [];
        for ($s = 0; $s < $homeSets + $awaySets; $s++) {
            $homeWonThis = $s < $homeSets;
            $loserPts = random_int($cap - 9, $cap - 2);
            $sets[] = $homeWonThis
                ? ['home' => $cap, 'away' => $loserPts]
                : ['home' => $loserPts, 'away' => $cap];
        }

        return [$homeSets, $awaySets, $sets];
    }

    private function playerStats(GameMatch $m, string $sport): void
    {
        $m->stats()->delete();

        $this->statsForSide($m, (string) $m->home_team_id, (int) $m->home_score, $sport);
        $this->statsForSide($m, (string) $m->away_team_id, (int) $m->away_score, $sport);
    }

    /**
     * Spread a side's score over its squad, plus the cards that feed the
     * fair-play tiebreaker.
     */
    private function statsForSide(GameMatch $m, string $teamId, int $score, string $sport): void
    {
        $players = Player::where('team_id', $teamId)->pluck('id')->all();
        if (empty($players)) {
            return;
        }

        /** @var array<string, array<string, int>> $tally */
        $tally = [];
        $bump = function (string $pid, string $key, int $by = 1) use (&$tally): void {
            $tally[$pid][$key] = ($tally[$pid][$key] ?? 0) + $by;
        };

        if (MatchScoring::isSetBased($sport)) {
            foreach ((array) array_rand($players, min(3, count($players))) as $idx) {
                $pid = $players[$idx];
                $bump($pid, 'points', random_int(6, 16));
                $bump($pid, 'aces', random_int(0, 3));
                if ($sport === 'volleyball') {
                    $bump($pid, 'blocks', random_int(0, 3));
                }
            }
        } else {
            for ($g = 0; $g < $score; $g++) {
                $bump($players[array_rand($players)], 'goals');
            }
            for ($a = 0; $a < (int) floor($score * 0.6); $a++) {
                $bump($players[array_rand($players)], 'assists');
            }
            if (random_int(0, 1) === 0) {
                $bump($players[array_rand($players)], 'yellow_cards');
            }
            if (random_int(0, 9) === 0) {
                $bump($players[array_rand($players)], 'red_cards');
            }
        }

        $rows = [];
        foreach ($tally as $pid => $stats) {
            foreach ($stats as $key => $value) {
                if ($value <= 0) {
                    continue;
                }

                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'match_id' => $m->id,
                    'team_id' => $teamId,
                    'player_id' => $pid,
                    'stat_key' => $key,
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($rows) {
            DB::table('player_match_stats')->insert($rows);
        }
    }
}
