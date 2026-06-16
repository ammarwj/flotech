<?php

namespace App\Services;

use App\Models\Event;
use App\Models\GameMatch;
use Illuminate\Support\Carbon;

/**
 * Generates fixtures for an event. Phase 1 supports single round-robin (league)
 * using the circle method; knockout brackets are a follow-up.
 */
class ScheduleService
{
    /**
     * (Re)generate a single round-robin schedule for the event's approved teams.
     * Existing matches are cleared first.
     *
     * @return int number of matches created
     */
    public function generateRoundRobin(Event $event): int
    {
        $teams = $event->teams()
            ->where('status', 'approved')
            ->orderBy('name')
            ->pluck('id')
            ->all();

        if (count($teams) < 2) {
            return 0;
        }

        // Odd team count → add a bye marker (null) so everyone rests once.
        if (count($teams) % 2 !== 0) {
            $teams[] = null;
        }

        $n = count($teams);
        $rounds = $n - 1;
        $half = intdiv($n, 2);

        $start = $event->start_date ? Carbon::parse($event->start_date) : Carbon::now()->startOfDay();

        $event->matches()->delete();

        $created = 0;
        $arr = $teams;

        for ($r = 0; $r < $rounds; $r++) {
            $order = 0;

            for ($i = 0; $i < $half; $i++) {
                $home = $arr[$i];
                $away = $arr[$n - 1 - $i];

                if ($home === null || $away === null) {
                    continue; // bye
                }

                GameMatch::create([
                    'event_id' => $event->id,
                    'round' => $r + 1,
                    'order' => $order++,
                    'home_team_id' => $home,
                    'away_team_id' => $away,
                    'scheduled_at' => $start->copy()->addDays($r),
                    'status' => 'scheduled',
                ]);
                $created++;
            }

            // Rotate all but the first element (circle method).
            $fixed = array_shift($arr);
            $last = array_pop($arr);
            array_unshift($arr, $last);
            array_unshift($arr, $fixed);
        }

        return $created;
    }

    /**
     * (Re)generate a single-elimination bracket. The bracket is padded to the
     * next power of two; the first seeds receive byes. All rounds are created
     * up front (later rounds start with empty slots) and bye winners are
     * advanced immediately.
     *
     * @return int total matches created (bracketSize - 1)
     */
    public function generateKnockout(Event $event): int
    {
        $teams = $event->teams()
            ->where('status', 'approved')
            ->orderBy('name')
            ->pluck('id')
            ->all();

        $n = count($teams);
        if ($n < 2) {
            return 0;
        }

        $bracketSize = 1;
        while ($bracketSize < $n) {
            $bracketSize *= 2;
        }
        $totalRounds = (int) log($bracketSize, 2);
        $byes = $bracketSize - $n;

        $start = $event->start_date ? Carbon::parse($event->start_date) : Carbon::now()->startOfDay();

        $event->matches()->delete();

        // Round 1: first `byes` matches get a bye (home only, walkover).
        $matchesR1 = intdiv($bracketSize, 2);
        $queue = $teams;
        $round1 = [];

        for ($o = 0; $o < $matchesR1; $o++) {
            $home = array_shift($queue);
            $away = $o < $byes ? null : array_shift($queue);

            $round1[] = GameMatch::create([
                'event_id' => $event->id,
                'round' => 1,
                'order' => $o,
                'home_team_id' => $home,
                'away_team_id' => $away,
                'scheduled_at' => $start,
                'status' => $away === null ? 'finished' : 'scheduled', // bye = walkover
                'confirmed_at' => $away === null ? now() : null, // byes are auto-final
            ]);
        }

        // Empty matches for every later round.
        for ($r = 2; $r <= $totalRounds; $r++) {
            $cnt = intdiv($bracketSize, 2 ** $r);
            for ($o = 0; $o < $cnt; $o++) {
                GameMatch::create([
                    'event_id' => $event->id,
                    'round' => $r,
                    'order' => $o,
                    'scheduled_at' => $start->copy()->addDays($r - 1),
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
     */
    public function advanceWinner(GameMatch $match): void
    {
        $winner = $this->winnerOf($match);
        if ($winner === null) {
            return;
        }

        $maxRound = (int) $match->event->matches()->max('round');
        if ($match->round >= $maxRound) {
            return; // final has no parent
        }

        $parent = $match->event->matches()
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
        }

        return null;
    }

    /**
     * (Re)generate a double-elimination bracket (winners + losers + grand final
     * with reset). Requires a power-of-two team count of at least 4.
     *
     * @return int total matches, or -1 when the team count is unsupported
     */
    public function generateDoubleElim(Event $event): int
    {
        $teams = $event->teams()
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
        $start = $event->start_date ? Carbon::parse($event->start_date) : Carbon::now()->startOfDay();

        $event->matches()->delete();

        // Winners bracket round 1 (seeded), then empty later rounds.
        for ($o = 0; $o < intdiv($bracketSize, 2); $o++) {
            $this->makeMatch($event, 'winners', 1, $o, $start, $teams[2 * $o], $teams[2 * $o + 1]);
        }
        for ($r = 2; $r <= $k; $r++) {
            for ($o = 0; $o < intdiv($bracketSize, 2 ** $r); $o++) {
                $this->makeMatch($event, 'winners', $r, $o, $start->copy()->addDays($r - 1));
            }
        }

        // Losers bracket: 2(k-1) rounds, all empty.
        $lbRounds = 2 * ($k - 1);
        for ($l = 1; $l <= $lbRounds; $l++) {
            for ($o = 0; $o < $this->lbMatchCount($bracketSize, $l); $o++) {
                $this->makeMatch($event, 'losers', $l, $o, $start->copy()->addDays($k + $l));
            }
        }

        // Grand final + potential reset.
        $this->makeMatch($event, 'grand_final', 1, 0, $start->copy()->addDays($k + $lbRounds + 1));
        $this->makeMatch($event, 'grand_final', 2, 0, $start->copy()->addDays($k + $lbRounds + 2));

        return $event->matches()->count();
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

        $k = (int) $match->event->matches()->where('bracket', 'winners')->max('round');

        if ($match->bracket === 'grand_final') {
            if ($match->round === 1) {
                $reset = $this->findMatch($match->event, 'grand_final', 2, 0);
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
                $this->place($match->event, 'winners', $match->round + 1, intdiv($match->order, 2), $match->order % 2 === 0, $winner);
            } else {
                $this->place($match->event, 'grand_final', 1, 0, true, $winner);
            }

            if ($loser !== null) {
                if ($match->round === 1) {
                    $this->place($match->event, 'losers', 1, intdiv($match->order, 2), $match->order % 2 === 0, $loser);
                } else {
                    $this->place($match->event, 'losers', 2 * ($match->round - 1), $match->order, false, $loser);
                }
            }

            return;
        }

        if ($match->bracket === 'losers') {
            $lbRounds = 2 * ($k - 1);
            if ($match->round >= $lbRounds) {
                $this->place($match->event, 'grand_final', 1, 0, false, $winner);
            } elseif ($match->round % 2 === 1) {
                // minor → next (major) round, home slot
                $this->place($match->event, 'losers', $match->round + 1, $match->order, true, $winner);
            } else {
                // major → next (minor) round, paired
                $this->place($match->event, 'losers', $match->round + 1, intdiv($match->order, 2), $match->order % 2 === 0, $winner);
            }
        }
    }

    protected function lbMatchCount(int $bracketSize, int $round): int
    {
        $base = intdiv($bracketSize, 4);
        $group = intdiv($round + 1, 2); // ceil(round / 2)

        return intdiv($base, 2 ** ($group - 1));
    }

    protected function makeMatch(Event $event, string $bracket, int $round, int $order, Carbon $when, ?string $home = null, ?string $away = null): GameMatch
    {
        return GameMatch::create([
            'event_id' => $event->id,
            'bracket' => $bracket,
            'round' => $round,
            'order' => $order,
            'home_team_id' => $home,
            'away_team_id' => $away,
            'scheduled_at' => $when,
            'status' => 'scheduled',
        ]);
    }

    protected function findMatch(Event $event, string $bracket, int $round, int $order): ?GameMatch
    {
        return $event->matches()
            ->where('bracket', $bracket)
            ->where('round', $round)
            ->where('order', $order)
            ->first();
    }

    protected function place(Event $event, string $bracket, int $round, int $order, bool $home, string $teamId): void
    {
        $target = $this->findMatch($event, $bracket, $round, $order);
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
     */
    public function applySchedule(Event $event, array $opts = []): void
    {
        $matches = $event->matches()->orderBy('round')->orderBy('order')->get();
        if ($matches->isEmpty()) {
            return;
        }

        $startDate = ! empty($opts['start_date'])
            ? Carbon::parse($opts['start_date'])->startOfDay()
            : ($event->start_date ? Carbon::parse($event->start_date)->startOfDay() : Carbon::now()->startOfDay());
        $endDate = $event->end_date ? Carbon::parse($event->end_date)->startOfDay() : null;

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
        // days only when a round has more matches than a day can hold.
        $rounds = $matches->groupBy('round');
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
                    'scheduled_at' => $day->copy()->addMinutes($time),
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
