<?php

namespace App\Services;

use App\Models\EventCategory;
use App\Models\GameMatch;
use App\Support\DisciplineRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who is not allowed to play, and in which fixture.
 *
 * Everything here is *derived* from player_match_stats on every read; nothing
 * about a suspension is stored. That is not a shortcut — it is the only shape
 * that survives the write path. MatchController::saveMatchStats() deletes every
 * stat row of a match and recreates it, so an organizer correcting a typo
 * downward (three yellows back to two) produces no event a stored ban could
 * listen for. The ban would outlive the card that caused it and claim a player
 * is suspended over something no longer in the database — the same class of bug
 * as a standings table that disagrees with the gate reading it.
 *
 * The cost is two queries and one O(n) pass, so there is nothing to trade away.
 *
 * Known limits, both inherent to the data rather than to this code:
 * - player_match_stats is an aggregate per match with no ordering, so a second
 *   yellow that became a red (one incident) cannot be told apart from two
 *   yellows plus a straight red (two incidents). A red on the same row is
 *   therefore read as *the same* sending-off and absorbs the one the yellows
 *   would have produced: recording one incident twice is what an organizer
 *   actually types, and banning them for two matches over it is the error that
 *   would be noticed.
 * - Nothing records who actually took the field — goal sports have no lineup
 *   input — so a ban is assumed to have been served by the team's next official
 *   fixture. An organizer who fields the player anyway gets a warning, not a
 *   correction.
 */
class DisciplineService
{
    /**
     * Chronological rank of a stage, for fixtures that share a kickoff or have
     * none. Deliberately not the alphabetical `coalesce(stage,'')` that
     * orderedMatches() uses: alphabetically 'playoff' sorts after 'knockout',
     * which is backwards in time.
     */
    private const STAGE_RANK = ['group' => 0, 'playoff' => 1, 'knockout' => 2];

    /**
     * @return array{enabled: bool, rules: array<string, mixed>|null, players: array<int, array<string, mixed>>, matches: object}
     */
    public function forCategory(EventCategory $category): array
    {
        $rules = DisciplineRules::forCategory($category);

        if (! $rules->enabled) {
            return ['enabled' => false, 'rules' => null, 'players' => [], 'matches' => (object) []];
        }

        $timeline = $this->timeline($category);
        [$cards, $people] = $this->cards($category, $rules);

        $players = [];
        /** @var array<string, array<int, array<string, mixed>>> $bans */
        $bans = [];

        foreach ($this->teamIds($timeline) as $teamId) {
            $mine = $timeline->filter(fn (GameMatch $m) => $m->home_team_id === $teamId || $m->away_team_id === $teamId);

            // Two different questions, and they need two different tests.
            //
            // To issue or serve a ban, both sides must be there: a fixture
            // missing one is a bye, and there is no bench to sit on in a match
            // that was never played.
            $official = $mine->filter(
                fn (GameMatch $m) => $m->home_team_id !== null && $m->away_team_id !== null && $this->isOfficial($m),
            );

            // To *carry a warning*, only this team has to be there. A bracket
            // slot whose opponent is still TBD is exactly where the organizer
            // most needs to know their player is suspended — waiting for the
            // draw to seat the other side would surface it too late, or after
            // the fixture is already being played.
            $pending = $mine->filter(fn (GameMatch $m) => $this->isPending($m))->values();

            $running = [];   // yellows since the last ban, per player
            $owed = [];      // bans issued but not yet sat out
            $issued = [];
            $served = [];
            $totals = [];    // player => ['yellow' => n, 'red' => n]
            $reason = [];
            $resetDone = false;

            foreach ($official as $match) {
                // A knockout stage wipes the running yellows but never an
                // outstanding ban — that is still owed. Only hybrid categories
                // set stage='knockout'; in a straight knockout every fixture is
                // stage null, so there is no boundary to detect and the option
                // correctly does nothing rather than firing at a random point.
                if ($rules->resetYellowOnKnockout && ! $resetDone
                    && $match->stage === 'knockout' && $category->engine() === 'hybrid') {
                    $running = [];
                    $resetDone = true;
                }

                // Serve before reading, never the other way round: a ban issued
                // in this very match must not be discharged by it. Reversed, the
                // ban is settled the moment it is handed out and this feature
                // never warns about anything.
                foreach ($owed as $playerId => $remaining) {
                    if ($remaining > 0) {
                        $owed[$playerId] = $remaining - 1;
                        $served[$playerId] = ($served[$playerId] ?? 0) + 1;
                    }
                }

                foreach ($cards[$match->id] ?? [] as $playerId => $card) {
                    // A match carries both teams' cards; this pass owns one of
                    // them. Without this the opponent's players would be walked
                    // along the wrong team's fixture list and serve their bans
                    // against matches they were never in.
                    if (($people[$playerId]['team_id'] ?? null) !== $teamId) {
                        continue;
                    }

                    if ($card['yellow'] === 0 && $card['red'] === 0) {
                        continue;
                    }

                    $totals[$playerId]['yellow'] = ($totals[$playerId]['yellow'] ?? 0) + $card['yellow'];
                    $totals[$playerId]['red'] = ($totals[$playerId]['red'] ?? 0) + $card['red'];

                    $yellow = $card['yellow'];
                    $expelled = false;

                    // Two yellows in one match are a sending-off, not a step
                    // towards the accumulation ban. At most one per match: a
                    // player leaves the field once, so a larger number is a
                    // typo whose remainder is better spent on the tally below
                    // than on inventing a second dismissal.
                    if ($rules->yellowsPerExpulsion > 0 && $yellow >= $rules->yellowsPerExpulsion) {
                        $expelled = true;
                        // The yellows that produced it never reach the running
                        // count. Letting them through would punish the one
                        // incident twice — the sending-off now, and again when
                        // the same cards tip the accumulation threshold later.
                        $yellow -= $rules->yellowsPerExpulsion;
                    }

                    // Same incident, recorded twice: see the class docblock.
                    if ($card['red'] > 0) {
                        $expelled = false;
                    }

                    $count = ($running[$playerId] ?? 0) + $yellow;

                    // A loop, not an `if`: value is an aggregate, so an organizer
                    // may type two yellows for one player in one match and cross
                    // the threshold more than once at a time.
                    while ($count >= $rules->yellowThreshold) {
                        $count -= $rules->yellowThreshold;
                        $owed[$playerId] = ($owed[$playerId] ?? 0) + $rules->yellowBanMatches;
                        $issued[$playerId] = ($issued[$playerId] ?? 0) + $rules->yellowBanMatches;
                        $reason[$playerId] = 'yellow_accumulation';
                    }

                    $running[$playerId] = $count;

                    if ($expelled && $rules->expulsionBanMatches > 0) {
                        $owed[$playerId] = ($owed[$playerId] ?? 0) + $rules->expulsionBanMatches;
                        $issued[$playerId] = ($issued[$playerId] ?? 0) + $rules->expulsionBanMatches;
                        // Outranks an accumulation crossed in the same match for
                        // the same reason a red outranks both: it is the heavier
                        // fact, and the one the organizer needs to read.
                        $reason[$playerId] = 'second_yellow';
                    }

                    if ($card['red'] > 0) {
                        $owed[$playerId] = ($owed[$playerId] ?? 0) + $rules->redBanMatches * $card['red'];
                        $issued[$playerId] = ($issued[$playerId] ?? 0) + $rules->redBanMatches * $card['red'];
                        // Written last, so it wins the reason over both an
                        // accumulation and a sending-off picked up in the same
                        // match: it is the heaviest fact, and the one the
                        // organizer needs to read.
                        $reason[$playerId] = 'red_card';
                    }
                }
            }

            foreach (array_keys($totals + $owed) as $playerId) {
                $person = $people[$playerId] ?? null;

                if ($person === null) {
                    continue;
                }

                $remaining = $owed[$playerId] ?? 0;

                $players[] = [
                    ...$person,
                    'yellow_total' => $totals[$playerId]['yellow'] ?? 0,
                    'red_total' => $totals[$playerId]['red'] ?? 0,
                    'yellow_running' => $running[$playerId] ?? 0,
                    'bans_issued' => $issued[$playerId] ?? 0,
                    'bans_served' => $served[$playerId] ?? 0,
                    'bans_remaining' => $remaining,
                ];

                // One outstanding ban match maps onto one upcoming fixture. A
                // player owing two sits out the next two; a player owing more
                // than the team has left simply runs out of fixtures.
                foreach ($pending->take($remaining) as $match) {
                    $bans[$match->id][] = [
                        ...$person,
                        'reason' => $reason[$playerId] ?? 'yellow_accumulation',
                        'bans_remaining' => $remaining,
                    ];
                }
            }
        }

        usort($players, fn ($a, $b) => [$b['bans_remaining'], $b['red_total'], $b['yellow_total'], $a['player_name']]
            <=> [$a['bans_remaining'], $a['red_total'], $a['yellow_total'], $b['player_name']]);

        // Cast: an empty map is [] in JSON, not {}, and the client indexes it by
        // match id. Same trap the public social links object carries.
        return ['enabled' => true, 'rules' => $rules->toArray(), 'players' => $players, 'matches' => (object) $bans];
    }

    /**
     * Every fixture of the category in the order they are played.
     *
     * Sorted once here rather than per team: N sorts of the same list can drift,
     * and the knockout boundary above has to mean the same thing for everyone.
     *
     * @return Collection<int, GameMatch>
     */
    private function timeline(EventCategory $category): Collection
    {
        return $category->matches()
            ->select(['id', 'stage', 'round', 'order', 'home_team_id', 'away_team_id', 'status', 'confirmed_at', 'scheduled_at'])
            ->get()
            ->sortBy(fn (GameMatch $m) => self::sortKey($m), SORT_REGULAR)
            ->values();
    }

    /**
     * @return array<int, mixed>
     */
    private static function sortKey(GameMatch $match): array
    {
        return [
            // Undated fixtures go last, not first. A fixture nobody has given a
            // kickoff to is almost certainly one nobody has played; sorting it
            // to the front would let it absorb a ban issued weeks later.
            $match->scheduled_at?->getTimestamp() ?? PHP_INT_MAX,
            self::STAGE_RANK[$match->stage] ?? 0,
            $match->round ?? 0,
            $match->order ?? 0,
            $match->id,
        ];
    }

    /**
     * A result counts once it is final — the same gate the leaderboard and the
     * standings table use. Reading cards under a looser one would let the
     * Statistik tab and the ban warning show different totals on one screen.
     */
    private function isOfficial(GameMatch $match): bool
    {
        return $match->status === 'finished' && $match->confirmed_at !== null;
    }

    /** Still to be played, so still able to carry a warning. */
    private function isPending(GameMatch $match): bool
    {
        return $match->status !== 'cancelled' && ! $this->isOfficial($match);
    }

    /**
     * @param  Collection<int, GameMatch>  $timeline
     * @return array<int, string>
     */
    private function teamIds(Collection $timeline): array
    {
        return $timeline
            ->flatMap(fn (GameMatch $m) => [$m->home_team_id, $m->away_team_id])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Every card in the category, plus who the players are.
     *
     * One query, shaped like PlayerStatService::leaderboard(). The stage is
     * deliberately *not* filtered, unlike StandingService::fairPlayPoints():
     * that one excludes the knockout and deciders because it feeds a standings
     * criterion and must not loop back into itself. A ban is not a standings
     * criterion — it is an administrative fact about a person, and one earned in
     * the group stage plainly carries into the bracket. Branching on `stage` at
     * all is what let a past bug hide half the application from itself; this
     * reader avoids that by never asking.
     *
     * @return array{0: array<string, array<string, array{yellow: int, red: int}>>, 1: array<string, array<string, mixed>>}
     */
    private function cards(EventCategory $category, DisciplineRules $rules): array
    {
        $keys = array_values(array_filter([$rules->yellowKey, $rules->redKey]));

        $rows = DB::table('player_match_stats')
            ->join('matches', 'matches.id', '=', 'player_match_stats.match_id')
            ->join('players', 'players.id', '=', 'player_match_stats.player_id')
            ->join('teams', 'teams.id', '=', 'player_match_stats.team_id')
            ->where('matches.category_id', $category->id)
            ->whereIn('player_match_stats.stat_key', $keys)
            ->select(
                'player_match_stats.match_id',
                'player_match_stats.player_id',
                // The snapshot on the stat row, not players.team_id: it records
                // who they turned out for when the card was shown.
                'player_match_stats.team_id',
                'player_match_stats.stat_key',
                'player_match_stats.value',
                'players.full_name',
                'players.jersey_number',
                'teams.name as team_name',
            )
            ->get();

        $cards = [];
        $people = [];

        foreach ($rows as $row) {
            $slot = $row->stat_key === $rules->redKey ? 'red' : 'yellow';

            $cards[$row->match_id][$row->player_id] ??= ['yellow' => 0, 'red' => 0];
            $cards[$row->match_id][$row->player_id][$slot] += (int) $row->value;

            $people[$row->player_id] ??= [
                'player_id' => $row->player_id,
                'player_name' => $row->full_name,
                // Stays a string: "07" and "7" are different shirts.
                'jersey_number' => $row->jersey_number,
                'team_id' => $row->team_id,
                'team_name' => $row->team_name,
            ];
        }

        return [$cards, $people];
    }
}
