<?php

namespace App\Support;

/**
 * Who plays whom in the next Swiss round.
 *
 * Pure like BracketSeeding: it takes a standings order, the pairs already
 * played and the byes already handed out, and answers with fixtures. No models,
 * no queries — which is what lets ScheduleService stay out of StandingService
 * and lets the awkward cases (a deadlocked field, an odd entry list) be tested
 * without a database.
 *
 * The two rules of the format live here, and neither is a setting:
 *
 *  - **No rematch.** Two entrants that have met are not paired again; the pairer
 *    falls through to the next-nearest unmet opponent in standings order. When
 *    the field is genuinely exhausted it pairs them anyway and *reports* how
 *    many repeats it had to accept, because refusing to produce a round is a
 *    worse answer than an honest one.
 *  - **Fair byes.** The bye goes to whoever has had the fewest, lowest-ranked
 *    first among those. Filtering on the minimum *is* the fairness rule: it
 *    keeps max − min at 1 without anybody tracking a quota.
 */
class SwissPairing
{
    /**
     * How much backtracking to spend before accepting repeats. Generous enough
     * for any realistic field (the search is near-linear while unmet opponents
     * remain) and bounded so a pathological `$met` map cannot hang a request.
     */
    private const BUDGET = 20000;

    /** An order-free key for a meeting between two entrants. */
    public static function pairKey(string $a, string $b): string
    {
        return $a < $b ? $a.'|'.$b : $b.'|'.$a;
    }

    /**
     * The entrant sitting out this round, or null when the field is even.
     *
     * @param  array<int, string>  $order  standings order, best first
     * @param  array<string, int>  $byeCounts  entrant id => byes already taken
     */
    public static function byeTeam(array $order, array $byeCounts): ?string
    {
        if (count($order) % 2 === 0) {
            return null;
        }

        $fewest = null;

        foreach ($order as $id) {
            $taken = $byeCounts[$id] ?? 0;

            if ($fewest === null || $taken < $fewest) {
                $fewest = $taken;
            }
        }

        // Lowest-ranked among those who have had the fewest: a bye is worth a
        // win, so it goes to whoever the table has already placed last.
        $candidate = null;

        foreach ($order as $id) {
            if (($byeCounts[$id] ?? 0) === $fewest) {
                $candidate = $id;
            }
        }

        return $candidate;
    }

    /**
     * Pair an even-sized order, nearest unmet opponent first.
     *
     * @param  array<int, string>  $order  standings order, best first
     * @param  array<string, bool>  $met  pairKey() => true for every meeting played
     * @return array{pairs: array<int, array{0: string, 1: string}>, rematches: int}
     */
    public static function pair(array $order, array $met): array
    {
        $order = array_values($order);

        if (count($order) < 2) {
            return ['pairs' => [], 'rematches' => 0];
        }

        $budget = self::BUDGET;
        $found = self::search($order, $met, $budget);

        if ($found !== null) {
            return ['pairs' => $found, 'rematches' => 0];
        }

        // The field is exhausted (or the search ran out of budget): fold the
        // order in sequence and say how many of those pairs are repeats. The
        // caller surfaces the number; it does not get to be a silent fallback.
        $pairs = [];
        $rematches = 0;

        for ($i = 0; $i + 1 < count($order); $i += 2) {
            $pairs[] = [$order[$i], $order[$i + 1]];

            if ($met[self::pairKey($order[$i], $order[$i + 1])] ?? false) {
                $rematches++;
            }
        }

        return ['pairs' => $pairs, 'rematches' => $rematches];
    }

    /**
     * Which side is nominally at home.
     *
     * Swiss has no real home ground, but a fixture still has to name two sides,
     * and the list the organizer reads is the one that decides which court the
     * entrant warms up on. Fewer home fixtures goes first; level, the
     * better-ranked entrant does.
     *
     * @param  array<string, int>  $homeCounts  entrant id => home fixtures so far
     * @return array{0: string, 1: string}  [home, away]
     */
    public static function sides(string $better, string $worse, array $homeCounts): array
    {
        $betterHome = $homeCounts[$better] ?? 0;
        $worseHome = $homeCounts[$worse] ?? 0;

        return $worseHome < $betterHome ? [$worse, $better] : [$better, $worse];
    }

    /**
     * Depth-first pairing with backtracking: take the top unpaired entrant and
     * try each unmet opponent below it, nearest first.
     *
     * Greedy alone deadlocks on fields that still have a valid pairing — the
     * last two left over may be the one pair that has already met — which is
     * why this backtracks rather than just scanning.
     *
     * @param  array<int, string>  $order
     * @param  array<string, bool>  $met
     * @return array<int, array{0: string, 1: string}>|null
     */
    private static function search(array $order, array $met, int &$budget): ?array
    {
        if ($order === []) {
            return [];
        }

        if ($budget-- <= 0) {
            return null;
        }

        $home = array_shift($order);

        foreach ($order as $i => $away) {
            if ($met[self::pairKey($home, $away)] ?? false) {
                continue;
            }

            $rest = $order;
            unset($rest[$i]);

            $tail = self::search(array_values($rest), $met, $budget);

            if ($tail !== null) {
                return array_merge([[$home, $away]], $tail);
            }

            if ($budget <= 0) {
                return null;
            }
        }

        return null;
    }
}
