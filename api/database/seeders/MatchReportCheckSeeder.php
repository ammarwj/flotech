<?php

namespace Database\Seeders;

use App\Models\EventPersonnel;
use App\Models\GameMatch;
use App\Models\MatchLineup;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Services\Catalog;
use App\Services\LineupService;
use App\Support\SquadRules;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Manual-QA seeder for the post-match report PDF.
 *
 * Takes a fixture that already exists — by default the first one of the
 * "Germaine Wilkinson" event — and puts it in the one state the report can be
 * downloaded in, with something in every section the sheet prints:
 *
 *   php artisan db:seed --class=MatchReportCheckSeeder
 *   EVENT=germaine-wilkinson MATCH=<uuid> php artisan db:seed --class=MatchReportCheckSeeder
 *
 * What it writes, and why each piece is there:
 *   - finished + scored + confirmed, because MatchReportController gates on
 *     isFinished() and the footnote reads confirmed_at;
 *   - a team sheet per side, through LineupService, so the squad tables split
 *     into inti / cadangan instead of printing one flat roster;
 *   - goals, assists and cards **spread across several players per side**, so
 *     the three filing tables each print more than one line and the two halves
 *     differ. A single scorer would make a report in which "every table shows
 *     the home side" and "the columns are wired to the right side" look the
 *     same;
 *   - one player with two yellows in one match, because that row is an
 *     aggregate the report expands into two lines — the one case where the
 *     table is not a row-per-stat-row;
 *   - one scorer left **off** both sheets, so the "Lainnya (tercatat
 *     statistik)" group has something in it: a goal that is on the scoreline
 *     and nowhere on the report is exactly what that group exists to prevent.
 *
 * Re-runnable: the stats and sheets are rewritten each time, so a changed
 * report can be regenerated without unpicking the previous run.
 */
class MatchReportCheckSeeder extends Seeder
{
    public function run(LineupService $lineups): void
    {
        $match = $this->match();

        if (! $match) {
            $this->command?->warn('Tidak ada pertandingan yang cocok. Set EVENT=<slug> atau MATCH=<uuid>.');

            return;
        }

        if (! $match->home_team_id || ! $match->away_team_id) {
            $this->command?->warn('Pertandingan itu belum punya kedua tim.');

            return;
        }

        $home = $this->side($match, $match->homeTeam, $lineups, goals: 2);
        $away = $this->side($match, $match->awayTeam, $lineups, goals: 1);

        // Written last: the report refuses anything that is not finished with a
        // scoreline, and the footnote prints whether it was ratified.
        $match->update([
            'status' => 'finished',
            'home_score' => $home['goals'],
            'away_score' => $away['goals'],
            'sets' => null,
            'confirmed_at' => Carbon::now(),
        ]);

        $event = $match->event;
        $route = "/api/v1/organizations/{$event->organization_id}/matches/{$match->id}/report";

        $this->command?->info("Laporan siap: {$match->homeTeam->name} {$home['goals']}-{$away['goals']} {$match->awayTeam->name}");
        $this->command?->table(
            ['Apa', 'Nilai'],
            [
                ['Event', $event->name],
                ['Match ID', $match->id],
                ['Unduh', $route],
                ['Kartu kuning', $home['yellow'].' / '.$away['yellow'].' baris'],
                ['Kartu merah', $home['red'].' / '.$away['red'].' baris'],
                ['Pencetak gol', $home['goals'].' / '.$away['goals'].' baris'],
            ],
        );
    }

    /**
     * The fixture to fill in: MATCH wins, then the first fixture of EVENT, then
     * the first fixture of the Germaine event this was written against.
     */
    private function match(): ?GameMatch
    {
        $relations = [
            'event.organization',
            'category',
            'homeTeam.players',
            'homeTeam.officials',
            'awayTeam.players',
            'awayTeam.officials',
        ];

        if ($id = env('MATCH')) {
            return GameMatch::with($relations)->find($id);
        }

        $slug = env('EVENT', 'germaine-wilkinson');

        return GameMatch::with($relations)
            ->whereHas('event', fn ($q) => $q->where('slug', $slug))
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id')
            ->orderBy('round')
            ->orderBy('order')
            ->first();
    }

    /**
     * One side: a team sheet, then the stats the three filing tables read.
     *
     * The scorer deliberately left off the sheet is the last player of the
     * roster, chosen *after* the sheet is built from the front of it, so the
     * two can never overlap however long the roster is.
     *
     * @return array{goals: int, yellow: int, red: int}
     */
    private function side(GameMatch $match, Team $team, LineupService $lineups, int $goals): array
    {
        /** @var list<Player> $roster */
        $roster = $team->players->values()->all();

        if (count($roster) < 4) {
            $this->command?->warn("Skuad {$team->name} terlalu kecil untuk diisi contoh.");

            return ['goals' => 0, 'yellow' => 0, 'red' => 0];
        }

        $named = $this->sheet($match, $team, $roster, $lineups);

        // Somebody with stats who is on nobody's sheet — the "Lainnya" group.
        $unnamed = collect($roster)->reject(fn (Player $p) => isset($named[$p->id]))->first()
            ?? $roster[count($roster) - 1];

        $stats = [];
        $bump = function (?Player $player, string $role, int $value) use (&$stats, $match): void {
            $key = $player ? Catalog::statKeyForRole($match->event->sport_type, $role) : null;

            if (! $key || $value <= 0) {
                return;
            }

            $stats[$player->id][$key] = ($stats[$player->id][$key] ?? 0) + $value;
        };

        // Goals: all but one from the sheet, the last from the unnamed player,
        // so both the squad table and the Lainnya group carry a scorer.
        $scorers = array_slice($roster, 0, max(0, $goals - 1));

        foreach ($scorers as $scorer) {
            $bump($scorer, 'goal', 1);
        }

        $bump($unnamed, 'goal', 1);
        $bump($roster[1] ?? null, 'assist', 1);

        // Two different players booked, and one of them twice in this fixture:
        // a single stat row of value 2 is what the yellow table expands into
        // two lines, which is the only row shape that is not one-for-one.
        $bump($roster[2] ?? null, 'yellow', 1);
        $bump($roster[3] ?? null, 'yellow', 2);
        $bump($roster[3] ?? null, 'red', 1);

        $match->stats()->where('team_id', $team->id)->delete();

        foreach ($stats as $playerId => $row) {
            foreach ($row as $key => $value) {
                $match->stats()->create([
                    'team_id' => $team->id,
                    'player_id' => $playerId,
                    'stat_key' => $key,
                    'value' => $value,
                ]);
            }
        }

        $yellowKey = Catalog::statKeyForRole($match->event->sport_type, 'yellow');
        $redKey = Catalog::statKeyForRole($match->event->sport_type, 'red');

        return [
            'goals' => $goals,
            'yellow' => collect($stats)->sum(fn ($r) => $r[$yellowKey] ?? 0),
            'red' => collect($stats)->sum(fn ($r) => $r[$redKey] ?? 0),
        ];
    }

    /**
     * A submitted-and-approved sheet for this side, through the real service.
     *
     * sync()/submit()/approve() are what the manager's editor and the referee's
     * screen call, so a sheet built this way carries the same invariants —
     * including the squad-size rules, which is why the split is read from
     * SquadRules rather than hardcoded at eleven.
     *
     * @param  list<Player>  $roster
     * @return array<string, true>  player ids that ended up on the sheet
     */
    private function sheet(GameMatch $match, Team $team, array $roster, LineupService $lineups): array
    {
        $rules = SquadRules::forCategory($match->category);

        if (! $rules->enabled) {
            return [];
        }

        // One short of the full roster, so there is always somebody left over
        // to carry the unnamed scorer above.
        $room = min($rules->maxPlayers(), count($roster) - 1);
        $starters = min($rules->starters, $room);
        $bench = max(0, $room - $starters);

        $payload = [];

        foreach (array_slice($roster, 0, $starters) as $player) {
            $payload[] = ['player_id' => $player->id, 'role' => 'starter'];
        }

        foreach (array_slice($roster, $starters, $bench) as $player) {
            $payload[] = ['player_id' => $player->id, 'role' => 'substitute'];
        }

        // Reopened first, because sync() refuses an approved sheet and this
        // seeder is meant to be re-runnable against a fixture it has already
        // filled in once. Written straight to the column rather than through a
        // service call: there is no "un-approve" in the real flow, and there
        // should not be one added for a QA seeder's sake.
        $this->reopen($match, $team);

        // A partial sheet cannot be submitted, by design — sync() takes the
        // ceiling, submit() takes the exact number. A roster too small for a
        // full eleven stays a draft, which the report prints just the same.
        $lineup = $lineups->sync($match, $team, $payload, $this->officials($team));

        if ($starters === $rules->starters) {
            $this->approve($lineup, $match, $lineups, $team);
        }

        return collect($payload)->mapWithKeys(fn ($r) => [$r['player_id'] => true])->all();
    }

    /** Put an already-approved sheet back in draft so sync() will take it. */
    private function reopen(GameMatch $match, Team $team): void
    {
        MatchLineup::where('match_id', $match->id)
            ->where('team_id', $team->id)
            ->update(['status' => 'draft', 'submitted_at' => null, 'reviewed_at' => null]);
    }

    /**
     * The bench, as the manager would name it: the team's own officials.
     *
     * @return array<int, array<string, mixed>>
     */
    private function officials(Team $team): array
    {
        return $team->officials
            ->take(LineupService::MAX_OFFICIALS)
            ->map(fn ($official) => ['team_official_id' => $official->id])
            ->values()
            ->all();
    }

    private function approve(MatchLineup $lineup, GameMatch $match, LineupService $lineups, Team $team): void
    {
        $manager = $team->manager_user_id ? User::find($team->manager_user_id) : null;
        $manager ??= User::find($match->event->organization->owner_id);

        if (! $manager) {
            return;
        }

        $referee = EventPersonnel::firstOrCreate(
            ['event_id' => $match->event_id, 'user_id' => $manager->id, 'kind' => 'referee'],
            ['full_name' => 'QA Wasit Laporan', 'email' => 'qa-report-referee@floevent.id'],
        );

        $lineups->submit($lineup, $manager);
        $lineups->approve($lineup, $referee);
    }
}
