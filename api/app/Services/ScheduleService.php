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
}
