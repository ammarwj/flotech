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
 * - Nothing records who actually took the field, so a ban is assumed to have been
 *   served by the team's next official fixture. `match_lineups` now exists and is
 *   deliberately not read here: it is a sheet submitted before kick-off, and a
 *   named substitute may never come on — reading it would claim a ban was served
 *   by someone who sat and watched. An organizer who fields a banned player
 *   anyway gets a warning, not a correction. Wiring appearances up is separate
 *   work and needs a record of who played, which nothing writes yet.
 *
 * Players and the bench run through one engine, from two tables.
 * `player_match_stats` and `official_match_stats` are kept apart on the write
 * side — an official is not a player, and the five readers of the first table
 * have no business with the bench — but a card means the same thing whoever it
 * was shown to, and the accumulation rules are the sport's, not the person's.
 * Two engines would be two copies of the serve-before-read ordering, the
 * two-yellows absorption and the knockout reset, and the copy nobody is watching
 * is the one that would drift.
 *
 * So everything below is keyed by a *person*: `person_type` ('player' |
 * 'official') plus `person_id`, carried in one composite map key so the two id
 * spaces cannot be confused even in a map. Reusing `player_id` for a coach was
 * the alternative, and it is the same mistake as printing "Nomor rekening" above
 * a phone number — every surface would read a field that is lying about what it
 * holds.
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
     * The `matches` map is keyed by fixture id and holds two kinds of entry,
     * told apart by `status`: 'upcoming' is someone who may not take part in a
     * fixture still to be played, 'served' is one who sat a ban out in a fixture
     * already finished and confirmed. Without the second kind the feature is
     * invisible the moment it does its job — a played fixture leaves $pending
     * forever, and "nobody was ever banned here" then looks exactly like
     * "somebody was, and served it here".
     *
     * `people` is the one list players and the bench share, and it replaced the
     * old `players` key outright rather than sitting beside it. Two spellings of
     * one tally is the shape this codebase keeps refusing: the second reader
     * drifts, and here it would drift by *omitting the bench* — the exact half
     * the feature was added for.
     *
     * @return array{enabled: bool, rules: array<string, mixed>|null, people: array<int, array<string, mixed>>, matches: object}
     */
    public function forCategory(EventCategory $category): array
    {
        $rules = DisciplineRules::forCategory($category);

        if (! $rules->enabled) {
            return ['enabled' => false, 'rules' => null, 'people' => [], 'matches' => (object) []];
        }

        $timeline = $this->timeline($category);
        [$cards, $people] = $this->cards($category, $rules);

        $rows = [];
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

            $running = [];   // yellows since the last ban, per person
            $owed = [];      // bans issued but not yet sat out
            $issued = [];
            $served = [];
            $totals = [];    // person key => ['yellow' => n, 'red' => n]
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
                foreach ($owed as $personKey => $remaining) {
                    if ($remaining > 0) {
                        $owed[$personKey] = $remaining - 1;
                        $served[$personKey] = ($served[$personKey] ?? 0) + 1;

                        // The fixture the ban was actually sat out in, recorded
                        // here rather than derived afterwards: once this match
                        // is finished and confirmed it is gone from $pending for
                        // good, and no reader downstream could reconstruct which
                        // fixture discharged the ban.
                        //
                        // $people and $reason are necessarily populated by now —
                        // a ban can only be served from the match *after* the one
                        // that issued it, so both were written in an earlier turn
                        // of this loop.
                        if (isset($people[$personKey])) {
                            $bans[$match->id][] = [
                                ...$people[$personKey],
                                'reason' => $reason[$personKey] ?? 'yellow_accumulation',
                                // Before the decrement, so the field means the
                                // same thing under both statuses: fixtures owed
                                // counting this one.
                                'bans_remaining' => $remaining,
                                'status' => 'served',
                            ];
                        }
                    }
                }

                foreach ($cards[$match->id] ?? [] as $personKey => $card) {
                    // A match carries both teams' cards; this pass owns one of
                    // them. Without this the opponent's players would be walked
                    // along the wrong team's fixture list and serve their bans
                    // against matches they were never in.
                    if (($people[$personKey]['team_id'] ?? null) !== $teamId) {
                        continue;
                    }

                    if ($card['yellow'] === 0 && $card['red'] === 0) {
                        continue;
                    }

                    $totals[$personKey]['yellow'] = ($totals[$personKey]['yellow'] ?? 0) + $card['yellow'];
                    $totals[$personKey]['red'] = ($totals[$personKey]['red'] ?? 0) + $card['red'];

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

                    $count = ($running[$personKey] ?? 0) + $yellow;

                    // A loop, not an `if`: value is an aggregate, so an organizer
                    // may type two yellows for one person in one match and cross
                    // the threshold more than once at a time.
                    while ($count >= $rules->yellowThreshold) {
                        $count -= $rules->yellowThreshold;
                        $owed[$personKey] = ($owed[$personKey] ?? 0) + $rules->yellowBanMatches;
                        $issued[$personKey] = ($issued[$personKey] ?? 0) + $rules->yellowBanMatches;
                        $reason[$personKey] = 'yellow_accumulation';
                    }

                    $running[$personKey] = $count;

                    if ($expelled && $rules->expulsionBanMatches > 0) {
                        $owed[$personKey] = ($owed[$personKey] ?? 0) + $rules->expulsionBanMatches;
                        $issued[$personKey] = ($issued[$personKey] ?? 0) + $rules->expulsionBanMatches;
                        // Outranks an accumulation crossed in the same match for
                        // the same reason a red outranks both: it is the heavier
                        // fact, and the one the organizer needs to read.
                        $reason[$personKey] = 'second_yellow';
                    }

                    if ($card['red'] > 0) {
                        $owed[$personKey] = ($owed[$personKey] ?? 0) + $rules->redBanMatches * $card['red'];
                        $issued[$personKey] = ($issued[$personKey] ?? 0) + $rules->redBanMatches * $card['red'];
                        // Written last, so it wins the reason over both an
                        // accumulation and a sending-off picked up in the same
                        // match: it is the heaviest fact, and the one the
                        // organizer needs to read.
                        $reason[$personKey] = 'red_card';
                    }
                }
            }

            foreach (array_keys($totals + $owed) as $personKey) {
                $person = $people[$personKey] ?? null;

                if ($person === null) {
                    continue;
                }

                $remaining = $owed[$personKey] ?? 0;

                $rows[] = [
                    ...$person,
                    'yellow_total' => $totals[$personKey]['yellow'] ?? 0,
                    'red_total' => $totals[$personKey]['red'] ?? 0,
                    'yellow_running' => $running[$personKey] ?? 0,
                    'bans_issued' => $issued[$personKey] ?? 0,
                    'bans_served' => $served[$personKey] ?? 0,
                    'bans_remaining' => $remaining,
                ];

                // One outstanding ban match maps onto one upcoming fixture. A
                // person owing two sits out the next two; one owing more than
                // the team has left simply runs out of fixtures.
                foreach ($pending->take($remaining) as $match) {
                    $bans[$match->id][] = [
                        ...$person,
                        'reason' => $reason[$personKey] ?? 'yellow_accumulation',
                        'bans_remaining' => $remaining,
                        'status' => 'upcoming',
                    ];
                }
            }
        }

        usort($rows, fn ($a, $b) => [$b['bans_remaining'], $b['red_total'], $b['yellow_total'], $a['person_name']]
            <=> [$a['bans_remaining'], $a['red_total'], $a['yellow_total'], $b['person_name']]);

        // Cast: an empty map is [] in JSON, not {}, and the client indexes it by
        // match id. Same trap the public social links object carries.
        return [
            'enabled' => true,
            'rules' => $rules->toArray(),
            'people' => $rows,
            'matches' => (object) $bans,
        ];
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
     * Every card in the category, plus who the people carrying them are.
     *
     * Two queries, one per owner table, shaped like
     * PlayerStatService::leaderboard(). They are unioned here rather than in SQL:
     * the two tables carry different owner columns and different name sources
     * (`players.jersey_number` has no counterpart on the bench), so a UNION would
     * need placeholder columns on both sides — the nullable-pair shape the tables
     * were split to avoid, rebuilt in the reader.
     *
     * Both maps are keyed by `personKey()`, so a player id and an official id can
     * never collide even though both are uuids from different tables.
     *
     * The stage is deliberately *not* filtered, unlike
     * StandingService::fairPlayPoints(): that one excludes the knockout and
     * deciders because it feeds a standings criterion and must not loop back into
     * itself. A ban is not a standings criterion — it is an administrative fact
     * about a person, and one earned in the group stage plainly carries into the
     * bracket. Branching on `stage` at all is what let a past bug hide half the
     * application from itself; this reader avoids that by never asking.
     *
     * @return array{0: array<string, array<string, array{yellow: int, red: int}>>, 1: array<string, array<string, mixed>>}
     */
    private function cards(EventCategory $category, DisciplineRules $rules): array
    {
        $keys = array_values(array_filter([$rules->yellowKey, $rules->redKey]));

        $cards = [];
        $people = [];

        $playerRows = DB::table('player_match_stats')
            ->join('matches', 'matches.id', '=', 'player_match_stats.match_id')
            ->join('players', 'players.id', '=', 'player_match_stats.player_id')
            ->join('teams', 'teams.id', '=', 'player_match_stats.team_id')
            ->where('matches.category_id', $category->id)
            ->whereIn('player_match_stats.stat_key', $keys)
            ->select(
                'player_match_stats.match_id',
                'player_match_stats.player_id as person_id',
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

        foreach ($playerRows as $row) {
            $this->collect($cards, $people, $rules, $row, 'player', [
                // Stays a string: "07" and "7" are different shirts.
                'jersey_number' => $row->jersey_number,
                'role' => null,
            ]);
        }

        $officialRows = DB::table('official_match_stats')
            ->join('matches', 'matches.id', '=', 'official_match_stats.match_id')
            ->join('team_officials', 'team_officials.id', '=', 'official_match_stats.team_official_id')
            ->join('teams', 'teams.id', '=', 'official_match_stats.team_id')
            ->where('matches.category_id', $category->id)
            ->whereIn('official_match_stats.stat_key', $keys)
            ->select(
                'official_match_stats.match_id',
                'official_match_stats.team_official_id as person_id',
                'official_match_stats.team_id',
                'official_match_stats.stat_key',
                'official_match_stats.value',
                'team_officials.full_name',
                'team_officials.role',
                'teams.name as team_name',
            )
            ->get();

        foreach ($officialRows as $row) {
            $this->collect($cards, $people, $rules, $row, 'official', [
                // The bench wears no number, and an empty string here would read
                // as a shirt on every surface that prints `#{number}`.
                'jersey_number' => null,
                // The role *key*; the label is the sport's and is resolved by the
                // client through the catalogue, the same way a roster row is.
                'role' => $row->role,
            ]);
        }

        return [$cards, $people];
    }

    /**
     * Fold one stat row into the two maps.
     *
     * Shared by both passes above so the card slot, the aggregation and the
     * identity shape are written once: two copies would be two answers to "what
     * counts as a red", and the copy nobody is watching is the one that drifts.
     *
     * @param  array<string, array<string, array{yellow: int, red: int}>>  $cards
     * @param  array<string, array<string, mixed>>  $people
     * @param  array<string, mixed>  $extra  the fields that differ between the two kinds
     */
    private function collect(array &$cards, array &$people, DisciplineRules $rules, object $row, string $type, array $extra): void
    {
        $key = self::personKey($type, $row->person_id);
        $slot = $row->stat_key === $rules->redKey ? 'red' : 'yellow';

        $cards[$row->match_id][$key] ??= ['yellow' => 0, 'red' => 0];
        $cards[$row->match_id][$key][$slot] += (int) $row->value;

        $people[$key] ??= [
            'person_type' => $type,
            'person_id' => $row->person_id,
            'person_name' => $row->full_name,
            'team_id' => $row->team_id,
            'team_name' => $row->team_name,
            ...$extra,
        ];
    }

    /**
     * One key for a person across both tables.
     *
     * `players` and `team_officials` are separate tables with separate uuid
     * spaces, so a bare id is only unique within its own. Prefixing is what lets
     * one map, one `$owed` tally and one `$running` counter serve both without a
     * second set of variables to keep in step.
     */
    private static function personKey(string $type, string $id): string
    {
        return $type.':'.$id;
    }
}
