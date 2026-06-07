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
}
