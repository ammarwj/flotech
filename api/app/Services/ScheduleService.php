<?php

namespace App\Services;

use App\Models\EventCategory;
use App\Models\GameMatch;
use App\Support\BracketSeeding;
use App\Support\HybridConfig;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Generates fixtures for a category: round-robin (league and hybrid group stage),
 * single/double elimination brackets, and the knockout stage of a hybrid event.
 *
 * Everything is scoped to one {@see EventCategory} — a single event may run
 * several categories (U17, Woman, …), each with its own format, so their
 * fixtures never share a table.
 */
class ScheduleService
{
    /**
     * (Re)generate a round-robin schedule for the category's approved teams.
     * Existing matches are cleared first. A double-leg (home & away) config
     * plays every pairing twice, with the venue reversed in the second leg.
     *
     * @return int number of matches created
     */
    public function generateRoundRobin(EventCategory $category): int
    {
        $config = HybridConfig::fromCategory($category);

        $teams = $category->teams()
            ->where('status', 'approved')
            ->orderBy('name')
            ->pluck('id')
            ->all();

        if (count($teams) < 2) {
            return 0;
        }

        $start = $this->categoryStart($category);

        $category->matches()->delete();

        $created = 0;
        $rounds = $this->roundRobinRounds($teams);

        foreach ($this->legRounds($rounds, $config->legs) as $roundIndex => $pairs) {
            $order = 0;
            foreach ($pairs as [$home, $away, $leg]) {
                GameMatch::create([
                    'event_id' => $category->event_id,
                    'category_id' => $category->id,
                    'round' => $roundIndex + 1,
                    'leg' => $leg,
                    'order' => $order++,
                    'home_team_id' => $home,
                    'away_team_id' => $away,
                    'scheduled_at' => $start->copy()->addDays($roundIndex)->utc(),
                    'status' => 'scheduled',
                ]);
                $created++;
            }
        }

        return $created;
    }

    /**
     * (Re)generate the group stage of a hybrid category: a round-robin inside every
     * group, with round N of each group played on the same matchday. Teams must
     * already be drawn into groups.
     *
     * @return int number of matches created
     */
    public function generateGroupStage(EventCategory $category): int
    {
        $config = HybridConfig::fromCategory($category);

        $teams = $category->teams()
            ->where('status', 'approved')
            ->whereNotNull('group_name')
            ->orderBy('name')
            ->get();

        if ($teams->count() < 2) {
            return 0;
        }

        $start = $this->categoryStart($category);

        $category->matches()->delete();

        $created = 0;
        /** @var array<int, int> $orders next slot number per matchday */
        $orders = [];

        foreach ($teams->groupBy('group_name')->sortKeys() as $groupName => $members) {
            if ($members->count() < 2) {
                continue; // a lone team in a group has nobody to play
            }

            $rounds = $this->roundRobinRounds($members->pluck('id')->all());

            foreach ($this->legRounds($rounds, $config->legs) as $roundIndex => $pairs) {
                $round = $roundIndex + 1;

                foreach ($pairs as [$home, $away, $leg]) {
                    $orders[$round] ??= 0;

                    GameMatch::create([
                        'event_id' => $category->event_id,
                        'category_id' => $category->id,
                        'stage' => 'group',
                        'group_name' => $groupName,
                        'round' => $round,
                        'leg' => $leg,
                        'order' => $orders[$round]++,
                        'home_team_id' => $home,
                        'away_team_id' => $away,
                        'scheduled_at' => $start->copy()->addDays($roundIndex)->utc(),
                        'status' => 'scheduled',
                    ]);
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * (Re)generate the knockout stage of a hybrid category from the teams that
     * qualified out of the groups (seeded best-first). The bracket is sized from
     * the config (or the next power of two above the field); unfilled slots
     * become BYEs and their opponent walks over into the next round.
     *
     * Group-stage fixtures are left untouched.
     *
     * @param  array<int, string>  $qualifiers  team ids, best seed first
     * @param  array<int, array{0: ?string, 1: ?string}>|null  $pairs  the organizer's
     *                                                                 own first-round slots, or null to seed automatically
     * @return int matches created
     */
    public function generateHybridKnockout(EventCategory $category, array $qualifiers, ?array $pairs = null): int
    {
        $config = HybridConfig::fromCategory($category);
        $size = $config->bracketSize();

        $qualifiers = array_slice(array_values($qualifiers), 0, $size);
        if (count($qualifiers) < 2) {
            return 0;
        }

        $category->matches()->where('stage', 'knockout')->delete();

        $totalRounds = (int) log($size, 2);
        $start = $this->knockoutStart($category);

        $groupOf = $category->teams()->pluck('group_name', 'id')->all();
        // Manual pairings skip seedOrder()/avoidSameGroup() on purpose — there
        // is nothing left to avoid once the organizer has named the ties.
        $pairs = $pairs === null
            ? $this->firstRoundPairs($size, $qualifiers, fn (string $id) => $groupOf[$id] ?? null)
            : $this->fillEmptySlots($pairs, $qualifiers);

        $round1 = [];
        foreach ($pairs as $o => [$home, $away]) {
            // An empty slot pair can't happen (qualifiers ≥ 2 fill the top seeds),
            // but a lone team means a bye: settled immediately, no opponent.
            $bye = $home !== null && $away === null;

            $round1[] = GameMatch::create([
                'event_id' => $category->event_id,
                'category_id' => $category->id,
                'stage' => 'knockout',
                'round' => 1,
                'order' => $o,
                'home_team_id' => $home,
                'away_team_id' => $away,
                'scheduled_at' => $start->copy()->utc(),
                'status' => $bye ? 'finished' : 'scheduled',
                'confirmed_at' => $bye ? now() : null,
            ]);
        }

        for ($r = 2; $r <= $totalRounds; $r++) {
            for ($o = 0; $o < intdiv($size, 2 ** $r); $o++) {
                GameMatch::create([
                    'event_id' => $category->event_id,
                    'category_id' => $category->id,
                    'stage' => 'knockout',
                    'round' => $r,
                    'order' => $o,
                    'scheduled_at' => $start->copy()->addDays($r - 1)->utc(),
                    'status' => 'scheduled',
                ]);
            }
        }

        foreach ($round1 as $m) {
            if ($m->home_team_id !== null && $m->away_team_id === null) {
                $this->advanceWinner($m->fresh());
            }
        }

        return $category->matches()->where('stage', 'knockout')->count();
    }

    /**
     * Top up an organizer's partial seeding with the teams they left out, in
     * slot order. Seeding only the two or three contentious ties is a valid
     * request; the rest of the field still has to be placed somewhere.
     *
     * @param  array<int, array{0: ?string, 1: ?string}>  $pairs
     * @param  array<int, string>  $pool  every team eligible for the bracket
     * @return array<int, array{0: ?string, 1: ?string}>
     */
    protected function fillEmptySlots(array $pairs, array $pool): array
    {
        $placed = [];
        foreach ($pairs as [$home, $away]) {
            foreach ([$home, $away] as $id) {
                if ($id !== null) {
                    $placed[$id] = true;
                }
            }
        }

        $queue = array_values(array_filter($pool, fn ($id) => ! isset($placed[$id])));

        foreach ($pairs as $o => [$home, $away]) {
            // Fill the home side first, so a leftover single team becomes a bye
            // rather than an away slot with nobody at home.
            if ($home === null && $queue !== []) {
                $home = array_shift($queue);
            }
            if ($away === null && $queue !== []) {
                $away = array_shift($queue);
            }

            $pairs[$o] = [$home, $away];
        }

        return $pairs;
    }

    /**
     * The first-round ties of a bracket of $size, in slot order: seed 1 v seed
     * N, and the halves recurse. Seeds beyond the field are missing, which is
     * what turns their opponent's tie into a bye.
     *
     * The seeds can be anything (team ids for the real bracket, slot descriptors
     * for a preview) as long as $groupOf can tell which group one came from.
     *
     * @param  array<int, mixed>  $seeds  best seed first
     * @param  callable(mixed): ?string  $groupOf
     * @return array<int, array{0: mixed, 1: mixed}>
     */
    public function firstRoundPairs(int $size, array $seeds, callable $groupOf): array
    {
        $slots = array_map(
            fn (int $seed) => $seeds[$seed - 1] ?? null,
            $this->seedOrder($size),
        );

        $pairs = [];
        for ($o = 0; $o < intdiv($size, 2); $o++) {
            $pairs[] = [$slots[2 * $o], $slots[2 * $o + 1]];
        }

        return $this->avoidSameGroup($pairs, $groupOf);
    }

    /**
     * Keep group-mates apart in the first knockout round: whenever a tie pairs
     * two seeds from the same group, swap one side with another tie that can
     * take it. Seeding (who is at home) is preserved; only the opponents move.
     *
     * @param  array<int, array{0: mixed, 1: mixed}>  $pairs
     * @param  callable(mixed): ?string  $groupOf
     * @return array<int, array{0: mixed, 1: mixed}>
     */
    protected function avoidSameGroup(array $pairs, callable $groupOf): array
    {
        $sameGroup = function ($a, $b) use ($groupOf) {
            if ($a === null || $b === null) {
                return false;
            }

            $groupA = $groupOf($a);

            return $groupA !== null && $groupA === $groupOf($b);
        };

        foreach ($pairs as $i => $pair) {
            if (! $sameGroup($pair[0], $pair[1])) {
                continue;
            }

            foreach ($pairs as $j => $other) {
                if ($i === $j) {
                    continue;
                }

                // Swapping the away sides must not create a clash in the other tie.
                if (! $sameGroup($pair[0], $other[1]) && ! $sameGroup($other[0], $pair[1])) {
                    $pairs[$i][1] = $other[1];
                    $pairs[$j][1] = $pair[1];
                    break;
                }
            }
        }

        return $pairs;
    }

    /**
     * The knockout stage starts the day after the last group fixture (or on the
     * event start date when there are none yet).
     */
    protected function knockoutStart(EventCategory $category): Carbon
    {
        // "The day after" is a calendar question, so it has to be asked in the
        // venue's zone: a 21:00 WIB kickoff is stored as 14:00Z the same day,
        // but a 07:00 WIB one is 00:00Z — reading those in UTC would put the
        // knockout on different days for kickoffs in the same evening.
        $tz = $category->timezone;
        $lastGroup = $category->matches()->where('stage', 'group')->max('scheduled_at');

        if ($lastGroup) {
            return Carbon::parse($lastGroup, 'UTC')->setTimezone($tz)->startOfDay()->addDay();
        }

        return $category->start_date
            ? Carbon::parse($category->start_date, $tz)->startOfDay()
            : Carbon::now($tz)->startOfDay();
    }

    /**
     * Round-robin pairings via the circle method: one entry per round, each a
     * list of [home, away] pairs. An odd field gets a bye (that team rests).
     *
     * @param  array<int, string>  $teamIds
     * @return array<int, array<int, array{0: string, 1: string}>>
     */
    protected function roundRobinRounds(array $teamIds): array
    {
        // Odd team count → add a bye marker (null) so everyone rests once.
        if (count($teamIds) % 2 !== 0) {
            $teamIds[] = null;
        }

        $n = count($teamIds);
        $half = intdiv($n, 2);
        $arr = $teamIds;
        $rounds = [];

        for ($r = 0; $r < $n - 1; $r++) {
            $pairs = [];

            for ($i = 0; $i < $half; $i++) {
                $home = $arr[$i];
                $away = $arr[$n - 1 - $i];

                if ($home === null || $away === null) {
                    continue; // bye
                }

                // Alternate home advantage each round so it evens out.
                $pairs[] = $r % 2 === 0 ? [$home, $away] : [$away, $home];
            }

            $rounds[] = $pairs;

            // Rotate all but the first element (circle method).
            $fixed = array_shift($arr);
            $last = array_pop($arr);
            array_unshift($arr, $last);
            array_unshift($arr, $fixed);
        }

        return $rounds;
    }

    /**
     * Expand round-robin rounds over the configured number of legs. The second
     * leg replays every round with the venue reversed, appended after the first.
     *
     * @param  array<int, array<int, array{0: string, 1: string}>>  $rounds
     * @return array<int, array<int, array{0: string, 1: string, 2: int}>>
     */
    protected function legRounds(array $rounds, int $legs): array
    {
        $out = [];

        for ($leg = 1; $leg <= max(1, $legs); $leg++) {
            foreach ($rounds as $pairs) {
                $out[] = array_map(
                    fn ($pair) => $leg === 2
                        ? [$pair[1], $pair[0], 2]   // return fixture: swap home & away
                        : [$pair[0], $pair[1], $leg],
                    $pairs,
                );
            }
        }

        return $out;
    }

    /**
     * Seed numbers in bracket-slot order, so the top seeds only meet in the late
     * rounds: 1,8,4,5,2,7,3,6 for a bracket of 8.
     *
     * @return array<int, int>
     */
    protected function seedOrder(int $size): array
    {
        $order = [1, 2];

        while (count($order) < $size) {
            $mirror = count($order) * 2 + 1;
            $next = [];
            foreach ($order as $seed) {
                $next[] = $seed;
                $next[] = $mirror - $seed;
            }
            $order = $next;
        }

        return $order;
    }

    /**
     * (Re)generate a single-elimination bracket. The bracket is padded to the
     * next power of two; the first seeds receive byes. All rounds are created
     * up front (later rounds start with empty slots) and bye winners are
     * advanced immediately.
     *
     * @param  array<int, array{0: ?string, 1: ?string}>|null  $pairs  the organizer's
     *                                                                 own first-round slots, or null to seed by team name
     * @return int total matches created (bracketSize - 1)
     */
    public function generateKnockout(EventCategory $category, ?array $pairs = null): int
    {
        $teams = $category->teams()
            ->where('status', 'approved')
            ->orderBy('name')
            ->pluck('id')
            ->all();

        $n = count($teams);
        if ($n < 2) {
            return 0;
        }

        $bracketSize = $pairs !== null ? 2 * count($pairs) : BracketSeeding::sizeFor($n);
        $totalRounds = (int) log($bracketSize, 2);
        $byes = $bracketSize - $n;

        $start = $this->categoryStart($category);

        $category->matches()->delete();

        // Round 1: first `byes` matches get a bye (home only, walkover).
        $matchesR1 = intdiv($bracketSize, 2);
        $queue = $teams;
        $round1 = [];

        if ($pairs !== null) {
            $pairs = $this->fillEmptySlots($pairs, $teams);
        }

        for ($o = 0; $o < $matchesR1; $o++) {
            if ($pairs !== null) {
                [$home, $away] = $pairs[$o];
            } else {
                $home = array_shift($queue);
                $away = $o < $byes ? null : array_shift($queue);
            }

            // A lone team walks over. Both slots empty is not a bye — manual
            // seeding can leave a tie unfilled — so it stays scheduled.
            $bye = $home !== null && $away === null;

            $round1[] = GameMatch::create([
                'event_id' => $category->event_id,
                'category_id' => $category->id,
                'round' => 1,
                'order' => $o,
                'home_team_id' => $home,
                'away_team_id' => $away,
                'scheduled_at' => $start->copy()->utc(),
                'status' => $bye ? 'finished' : 'scheduled', // bye = walkover
                'confirmed_at' => $bye ? now() : null, // byes are auto-final
            ]);
        }

        // Empty matches for every later round.
        for ($r = 2; $r <= $totalRounds; $r++) {
            $cnt = intdiv($bracketSize, 2 ** $r);
            for ($o = 0; $o < $cnt; $o++) {
                GameMatch::create([
                    'event_id' => $category->event_id,
                    'category_id' => $category->id,
                    'round' => $r,
                    'order' => $o,
                    'scheduled_at' => $start->copy()->addDays($r - 1)->utc(),
                    'status' => 'scheduled',
                ]);
            }
        }

        // Push bye teams into round 2.
        foreach ($round1 as $m) {
            if ($m->home_team_id !== null && $m->away_team_id === null) {
                $this->advanceWinner($m->fresh());
            }
        }

        return $bracketSize - 1;
    }

    /**
     * Propagate the winner of a finished knockout match into the next round's
     * slot. No-op for the final, draws, or undecided matches.
     *
     * Scoped to the match's own stage, so a hybrid category's knockout rounds never
     * collide with the group rounds sharing the same round numbers.
     */
    public function advanceWinner(GameMatch $match): void
    {
        $winner = $this->winnerOf($match);
        if ($winner === null) {
            return;
        }

        $maxRound = (int) $this->sameStage($match)->max('round');
        if ($match->round >= $maxRound) {
            return; // final has no parent
        }

        $parent = $this->sameStage($match)
            ->where('round', $match->round + 1)
            ->where('order', intdiv($match->order, 2))
            ->first();

        if (! $parent) {
            return;
        }

        // Even-ordered children feed the home slot, odd ones the away slot.
        if ($match->order % 2 === 0) {
            $parent->home_team_id = $winner;
        } else {
            $parent->away_team_id = $winner;
        }
        $parent->save();
    }

    /**
     * Undo every slot a match's winner reached, after its teams changed.
     *
     * Walks the same positional edges as advanceWinner(), upward, until it hits
     * one nothing ever travelled through.
     *
     * @return int matches reset
     */
    public function clearDownstream(GameMatch $match): int
    {
        $cleared = 0;
        $maxRound = (int) $this->sameStage($match)->max('round');
        $cursor = $match;

        while ($cursor->round < $maxRound) {
            $parent = $this->sameStage($cursor)
                ->where('round', $cursor->round + 1)
                ->where('order', intdiv($cursor->order, 2))
                ->first();

            if (! $parent) {
                break;
            }

            $slot = $cursor->order % 2 === 0 ? 'home_team_id' : 'away_team_id';

            // Nobody ever came up this edge, so no ancestor holds a team that
            // arrived by it. Walking on regardless would empty slots belonging
            // to the other half of the bracket.
            if ($parent->{$slot} === null) {
                break;
            }

            // The scoreline has to go with the team: a result recorded against
            // someone no longer in the fixture still hands winnerOf() a winner,
            // and that phantom keeps propagating on the next confirm. Emptying
            // it is also what guarantees the grandparent slot is already null,
            // which is exactly what the next iteration checks.
            $parent->update([
                $slot => null,
                'home_score' => null,
                'away_score' => null,
                'home_penalty' => null,
                'away_penalty' => null,
                'sets' => null,
                'status' => 'scheduled',
                'confirmed_at' => null,
            ]);

            $cleared++;
            $cursor = $parent;
        }

        return $cleared;
    }

    /**
     * Query over the other matches of the same category *and* stage.
     *
     * @return HasMany<GameMatch>
     */
    protected function sameStage(GameMatch $match)
    {
        $query = $match->category->matches();

        return $match->stage === null
            ? $query->whereNull('stage')
            : $query->where('stage', $match->stage);
    }

    protected function winnerOf(GameMatch $match): ?string
    {
        // Walkover: a lone team advances.
        if ($match->home_team_id !== null && $match->away_team_id === null) {
            return $match->home_team_id;
        }

        if ($match->home_score !== null && $match->away_score !== null) {
            if ($match->home_score > $match->away_score) {
                return $match->home_team_id;
            }
            if ($match->away_score > $match->home_score) {
                return $match->away_team_id;
            }

            // Level after normal time: the shootout decides who goes through.
            if ($match->home_penalty !== null && $match->away_penalty !== null) {
                if ($match->home_penalty > $match->away_penalty) {
                    return $match->home_team_id;
                }
                if ($match->away_penalty > $match->home_penalty) {
                    return $match->away_team_id;
                }
            }
        }

        return null;
    }

    /**
     * (Re)generate a double-elimination bracket (winners + losers + grand final
     * with reset). Requires a power-of-two team count of at least 4.
     *
     * @return int total matches, or -1 when the team count is unsupported
     */
    public function generateDoubleElim(EventCategory $category): int
    {
        $teams = $category->teams()
            ->where('status', 'approved')
            ->orderBy('name')
            ->pluck('id')
            ->all();

        $n = count($teams);
        if ($n < 4 || ($n & ($n - 1)) !== 0) {
            return -1; // not a power of two ≥ 4
        }

        $bracketSize = $n;
        $k = (int) log($bracketSize, 2);
        $start = $this->categoryStart($category);

        $category->matches()->delete();

        // Winners bracket round 1 (seeded), then empty later rounds.
        for ($o = 0; $o < intdiv($bracketSize, 2); $o++) {
            $this->makeMatch($category, 'winners', 1, $o, $start, $teams[2 * $o], $teams[2 * $o + 1]);
        }
        for ($r = 2; $r <= $k; $r++) {
            for ($o = 0; $o < intdiv($bracketSize, 2 ** $r); $o++) {
                $this->makeMatch($category, 'winners', $r, $o, $start->copy()->addDays($r - 1));
            }
        }

        // Losers bracket: 2(k-1) rounds, all empty.
        $lbRounds = 2 * ($k - 1);
        for ($l = 1; $l <= $lbRounds; $l++) {
            for ($o = 0; $o < $this->lbMatchCount($bracketSize, $l); $o++) {
                $this->makeMatch($category, 'losers', $l, $o, $start->copy()->addDays($k + $l));
            }
        }

        // Grand final + potential reset.
        $this->makeMatch($category, 'grand_final', 1, 0, $start->copy()->addDays($k + $lbRounds + 1));
        $this->makeMatch($category, 'grand_final', 2, 0, $start->copy()->addDays($k + $lbRounds + 2));

        return $category->matches()->count();
    }

    /**
     * Propagate a finished double-elimination match: winner forward, loser into
     * the losers bracket, with grand-final reset handling.
     */
    public function advanceDouble(GameMatch $match): void
    {
        $winner = $this->winnerOf($match);
        if ($winner === null) {
            return;
        }
        $loser = $winner === $match->home_team_id ? $match->away_team_id : $match->home_team_id;

        $k = (int) $match->category->matches()->where('bracket', 'winners')->max('round');

        if ($match->bracket === 'grand_final') {
            if ($match->round === 1) {
                $reset = $this->findMatch($match->category, 'grand_final', 2, 0);
                if ($reset && $winner === $match->away_team_id) {
                    // Losers-bracket side forced a decider.
                    $reset->update([
                        'home_team_id' => $match->home_team_id,
                        'away_team_id' => $match->away_team_id,
                        'home_score' => null,
                        'away_score' => null,
                        'status' => 'scheduled',
                    ]);
                } elseif ($reset) {
                    $reset->update(['home_team_id' => null, 'away_team_id' => null, 'status' => 'cancelled']);
                }
            }

            return;
        }

        if ($match->bracket === 'winners') {
            if ($match->round < $k) {
                $this->place($match->category, 'winners', $match->round + 1, intdiv($match->order, 2), $match->order % 2 === 0, $winner);
            } else {
                $this->place($match->category, 'grand_final', 1, 0, true, $winner);
            }

            if ($loser !== null) {
                if ($match->round === 1) {
                    $this->place($match->category, 'losers', 1, intdiv($match->order, 2), $match->order % 2 === 0, $loser);
                } else {
                    $this->place($match->category, 'losers', 2 * ($match->round - 1), $match->order, false, $loser);
                }
            }

            return;
        }

        if ($match->bracket === 'losers') {
            $lbRounds = 2 * ($k - 1);
            if ($match->round >= $lbRounds) {
                $this->place($match->category, 'grand_final', 1, 0, false, $winner);
            } elseif ($match->round % 2 === 1) {
                // minor → next (major) round, home slot
                $this->place($match->category, 'losers', $match->round + 1, $match->order, true, $winner);
            } else {
                // major → next (minor) round, paired
                $this->place($match->category, 'losers', $match->round + 1, intdiv($match->order, 2), $match->order % 2 === 0, $winner);
            }
        }
    }

    protected function lbMatchCount(int $bracketSize, int $round): int
    {
        $base = intdiv($bracketSize, 4);
        $group = intdiv($round + 1, 2); // ceil(round / 2)

        return intdiv($base, 2 ** ($group - 1));
    }

    protected function makeMatch(EventCategory $category, string $bracket, int $round, int $order, Carbon $when, ?string $home = null, ?string $away = null): GameMatch
    {
        return GameMatch::create([
            'event_id' => $category->event_id,
            'category_id' => $category->id,
            'bracket' => $bracket,
            'round' => $round,
            'order' => $order,
            'home_team_id' => $home,
            'away_team_id' => $away,
            'scheduled_at' => $when->copy()->utc(),
            'status' => 'scheduled',
        ]);
    }

    /**
     * Midnight on the category's first day, *in the venue's zone*. Generators
     * use this as a placeholder date until applySchedule() assigns real kickoff
     * times; it still has to be zoned, or the placeholder lands on the wrong
     * calendar day for any venue far enough from UTC.
     */
    protected function categoryStart(EventCategory $category): Carbon
    {
        $tz = $category->timezone;

        return $category->start_date
            ? Carbon::parse($category->start_date, $tz)->startOfDay()
            : Carbon::now($tz)->startOfDay();
    }

    protected function findMatch(EventCategory $category, string $bracket, int $round, int $order): ?GameMatch
    {
        return $category->matches()
            ->where('bracket', $bracket)
            ->where('round', $round)
            ->where('order', $order)
            ->first();
    }

    protected function place(EventCategory $category, string $bracket, int $round, int $order, bool $home, string $teamId): void
    {
        $target = $this->findMatch($category, $bracket, $round, $order);
        if (! $target) {
            return;
        }

        $target->{$home ? 'home_team_id' : 'away_team_id'} = $teamId;
        $target->save();
    }

    /**
     * Assign a concrete date/time (and venue lane) to every fixture using a
     * slot allocator. Each round is placed on its own day(s) — which keeps a
     * team from playing twice the same day in a league — and rounds are either
     * packed consecutively or spread evenly across the event's date range.
     *
     * Options (all optional): start_date, daily_start "H:i", daily_end "H:i",
     * match_minutes, break_minutes, venues, max_per_day, spread (bool).
     *
     * @param  array<string, mixed>  $opts
     * @param  string|null  $stage  limit to one stage of a hybrid category
     */
    public function applySchedule(EventCategory $category, array $opts = [], ?string $stage = null): void
    {
        $query = $category->matches();
        if ($stage !== null) {
            $query->where('stage', $stage);
        }

        // Group stage before knockout; single-stage categories have a null stage.
        $matches = $query
            ->orderByRaw("coalesce(stage, '') asc")
            ->orderBy('round')
            ->orderBy('order')
            ->get();

        if ($matches->isEmpty()) {
            return;
        }

        // Every wall-clock value below ("15:00", "the 3rd") means the venue's
        // zone, not the server's. The app runs in UTC, so parsing these without
        // a zone would silently write 15:00Z for a 15:00 WIB kickoff — the
        // fixture would then show up at 22:00.
        $tz = $category->timezone;

        $startDate = ! empty($opts['start_date'])
            ? Carbon::parse($opts['start_date'], $tz)->startOfDay()
            : ($stage === 'knockout'
                ? $this->knockoutStart($category)->startOfDay()
                : ($category->start_date ? Carbon::parse($category->start_date, $tz)->startOfDay() : Carbon::now($tz)->startOfDay()));
        $endDate = $category->end_date ? Carbon::parse($category->end_date, $tz)->startOfDay() : null;

        $startMin = $this->minutesOfDay($opts['daily_start'] ?? '15:00');
        $endMin = $this->minutesOfDay($opts['daily_end'] ?? '21:00');
        $dur = (int) ($opts['match_minutes'] ?? 90);
        $break = (int) ($opts['break_minutes'] ?? 15);
        $venues = max(1, (int) ($opts['venues'] ?? 1));
        $maxPerDay = isset($opts['max_per_day']) ? max(1, (int) $opts['max_per_day']) : null;
        $spread = (bool) ($opts['spread'] ?? true);

        // Kickoff times within the daily window.
        $times = [];
        for ($t = $startMin; $t + $dur <= $endMin; $t += $dur + $break) {
            $times[] = $t;
        }
        if (empty($times)) {
            $times[] = $startMin; // window smaller than one match → one per venue per day
        }

        $perDay = count($times) * $venues;
        if ($maxPerDay !== null) {
            $perDay = min($perDay, $maxPerDay);
        }
        $perDay = max(1, $perDay);

        // One round per day (a round never repeats a team), overflowing to extra
        // days only when a round has more matches than a day can hold. A hybrid
        // category's group and knockout rounds share round numbers, so the stage is
        // part of the key.
        $rounds = $matches->groupBy(fn (GameMatch $m) => ($m->stage ?? '').'#'.$m->round);
        $daysNeeded = 0;
        foreach ($rounds as $list) {
            $daysNeeded += (int) ceil($list->count() / $perDay);
        }

        $days = $this->scheduleDays($startDate, $endDate, $daysNeeded, $spread);
        $lastDay = count($days) - 1;

        $dayIdx = 0;
        foreach ($rounds as $list) {
            $slot = 0;
            foreach ($list as $m) {
                if ($slot >= $perDay) {
                    $dayIdx++;
                    $slot = 0;
                }
                $day = $days[min($dayIdx, $lastDay)];
                $lane = $slot % $venues;
                $time = $times[min(intdiv($slot, $venues), count($times) - 1)];

                $m->update([
                    // ->utc() is not optional: Eloquent's fromDateTime() formats
                    // the Carbon instance as-is, so without it the venue-local
                    // wall clock would land raw in a UTC column.
                    'scheduled_at' => $day->copy()->addMinutes($time)->utc(),
                    'venue' => $venues > 1 ? 'Lapangan '.($lane + 1) : null,
                ]);
                $slot++;
            }
            $dayIdx++; // next round starts on a fresh day
        }
    }

    private function minutesOfDay(string $hhmm): int
    {
        [$h, $m] = array_pad(explode(':', $hhmm), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }

    /**
     * Build $n calendar days from $start, either consecutive or spread evenly
     * across [$start, $end] when there's room and spreading is requested.
     *
     * @return array<int, Carbon>
     */
    private function scheduleDays(Carbon $start, ?Carbon $end, int $n, bool $spread): array
    {
        $n = max(1, $n);
        if ($n === 1) {
            return [$start->copy()];
        }

        $span = $end ? $start->diffInDays($end) : 0;

        // Not spreading, no end date, or not enough room → consecutive days.
        if (! $spread || ! $end || $span < $n - 1) {
            return array_map(fn ($i) => $start->copy()->addDays($i), range(0, $n - 1));
        }

        $days = [];
        $prev = -1;
        for ($i = 0; $i < $n; $i++) {
            $off = (int) round($i * $span / ($n - 1));
            if ($off <= $prev) {
                $off = $prev + 1; // keep strictly increasing
            }
            $prev = $off;
            $days[] = $start->copy()->addDays($off);
        }

        return $days;
    }
}
