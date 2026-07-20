<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The organizer's own first-round pairings for a knockout bracket.
 *
 * Auto seeding is the default and unchanged: hybrid brackets come from the
 * group standings, single-elimination ones from the team list. Manual seeding
 * replaces *only* the first round — later rounds are still created empty and
 * still filled by ScheduleService::advanceWinner().
 *
 * The payload is a slot map, not an ordered seed list, because an ordered list
 * cannot express "A plays B": seedOrder() mirrors it and avoidSameGroup() then
 * swaps the away sides, so the organizer would get fixtures they never chose.
 * Manual mode therefore skips both — their pairing *is* the intent.
 *
 * For the same reason a slot the organizer left empty stays empty: topping it up
 * with whoever was left over hands them a tie they never picked, which is the
 * very thing skipping seedOrder() avoids. What is *not* allowed is a team going
 * missing — unplacedTeams() refuses a payload that drops one out of the bracket,
 * so "empty slot" never quietly means "team forgotten".
 *
 * Each slot may also carry its own kickoff and venue. Those win over the slot
 * allocator (see lockedOrders() and ScheduleService::applySchedule()); a slot
 * that leaves them null is scheduled automatically as before.
 *
 * @phpstan-type Slot array{home: ?string, away: ?string, scheduled_at: ?string, venue: ?string}
 */
class BracketSeeding
{
    /**
     * Rules for the seeding sub-payload of a generate request.
     *
     * @param  int  $halfSize  first-round slots (bracket size / 2)
     * @param  array<int, string>|Collection<int, string>  $pool  team ids that may be placed
     * @return array<string, mixed>
     */
    public static function validationRules(int $halfSize, $pool): array
    {
        return [
            'seeding' => ['nullable', Rule::in(['auto', 'manual'])],
            'pairs' => ['required_if:seeding,manual', 'array', 'max:'.$halfSize],
            'pairs.*.order' => ['required', 'integer', 'min:0', 'max:'.max(0, $halfSize - 1)],
            // Both sides are optional: a slot may be sent empty on purpose, or
            // carry nothing but a kickoff and a venue. A null away side is a
            // bye, exactly as generation writes it.
            'pairs.*.home_team_id' => ['nullable', 'uuid', Rule::in($pool)],
            'pairs.*.away_team_id' => ['nullable', 'uuid', 'different:pairs.*.home_team_id', Rule::in($pool)],
            // Per-tie overrides; null means "let the allocator place it".
            'pairs.*.scheduled_at' => ['nullable', 'date'],
            'pairs.*.venue' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function isManual(array $validated): bool
    {
        return ($validated['seeding'] ?? 'auto') === 'manual';
    }

    /**
     * The validated payload as the `order => slot` map the schedule service
     * consumes, or null when seeding is automatic.
     *
     * Slots the organizer left out stay empty on every key — that is a real
     * empty tie, not a placeholder waiting to be topped up.
     *
     * @return array<int, Slot>|null
     */
    public static function normalize(array $validated, int $halfSize): ?array
    {
        if (! self::isManual($validated)) {
            return null;
        }

        $pairs = array_fill(0, $halfSize, self::slot(null, null));

        foreach ($validated['pairs'] ?? [] as $pair) {
            $home = $pair['home_team_id'] ?? null;
            $away = $pair['away_team_id'] ?? null;

            // A lone team is a bye, and generation only reads one as such when
            // it sits at home ($home !== null && $away === null). Left as an
            // away side it would be a tie nobody can win: not a bye, so never
            // advanced, and no opponent will ever arrive.
            if ($home === null && $away !== null) {
                [$home, $away] = [$away, null];
            }

            $pairs[$pair['order']] = self::slot(
                $home,
                $away,
                $pair['scheduled_at'] ?? null,
                $pair['venue'] ?? null,
            );
        }

        return $pairs;
    }

    /**
     * One first-round slot in the shape ScheduleService writes matches from.
     * Automatic seeding builds its slots through here too, so a single shape
     * reaches GameMatch::create() no matter which path produced it.
     *
     * @return Slot
     */
    public static function slot(
        ?string $home,
        ?string $away,
        ?string $scheduledAt = null,
        ?string $venue = null,
    ): array {
        return [
            'home' => $home,
            'away' => $away,
            'scheduled_at' => $scheduledAt,
            'venue' => $venue,
        ];
    }

    /**
     * Teams from the eligible pool that the organizer placed nowhere.
     *
     * The mirror of duplicateTeam(): one catches a team used twice, this one a
     * team used never. Leaving a *slot* empty is deliberate and allowed; losing
     * a team out of the bracket is not, and is silent without this check.
     *
     * @param  array<int, array<string, mixed>>  $pairs  the raw validated pairs
     * @param  array<int, string>|Collection<int, string>  $pool
     * @return array<int, string> unplaced team ids, in pool order
     */
    public static function unplacedTeams(array $pairs, $pool): array
    {
        $placed = [];

        foreach ($pairs as $pair) {
            foreach ([$pair['home_team_id'] ?? null, $pair['away_team_id'] ?? null] as $id) {
                if ($id !== null) {
                    $placed[$id] = true;
                }
            }
        }

        return array_values(array_filter(
            $pool instanceof Collection ? $pool->all() : $pool,
            fn ($id) => ! isset($placed[$id]),
        ));
    }

    /**
     * First-round slots the organizer gave their own kickoff or venue, which the
     * slot allocator must leave alone.
     *
     * @param  array<int, Slot>|null  $pairs  normalized slots, or null for auto seeding
     * @return array<int, int> slot orders
     */
    public static function lockedOrders(?array $pairs): array
    {
        if ($pairs === null) {
            return [];
        }

        return array_keys(array_filter(
            $pairs,
            fn (array $slot) => $slot['scheduled_at'] !== null || $slot['venue'] !== null,
        ));
    }

    /**
     * The first team id that occupies two slots, or null when every pick is
     * distinct. Rule::in cannot express this, and `distinct` cannot reach
     * across two sibling keys of the same row.
     */
    public static function duplicateTeam(array $pairs): ?string
    {
        $seen = [];

        foreach ($pairs as $pair) {
            foreach ([$pair['home_team_id'] ?? null, $pair['away_team_id'] ?? null] as $id) {
                if ($id === null) {
                    continue;
                }
                if (isset($seen[$id])) {
                    return $id;
                }
                $seen[$id] = true;
            }
        }

        return null;
    }

    /** Bracket size for a field of $teams: the next power of two, at least 2. */
    public static function sizeFor(int $teams): int
    {
        $size = 2;
        while ($size < $teams) {
            $size *= 2;
        }

        return $size;
    }
}
