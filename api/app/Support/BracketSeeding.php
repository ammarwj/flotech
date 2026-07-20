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
            'pairs.*.home_team_id' => ['required', 'uuid', Rule::in($pool)],
            // A null away side is a bye, exactly as generation writes it.
            'pairs.*.away_team_id' => ['nullable', 'uuid', 'different:pairs.*.home_team_id', Rule::in($pool)],
        ];
    }

    public static function isManual(array $validated): bool
    {
        return ($validated['seeding'] ?? 'auto') === 'manual';
    }

    /**
     * The validated payload as the `order => [home, away]` map the schedule
     * service consumes, or null when seeding is automatic.
     *
     * Slots the organizer left out are filled with `[null, null]`, so seeding
     * only the two or three contentious ties is a valid request.
     *
     * @return array<int, array{0: ?string, 1: ?string}>|null
     */
    public static function normalize(array $validated, int $halfSize): ?array
    {
        if (! self::isManual($validated)) {
            return null;
        }

        $pairs = array_fill(0, $halfSize, [null, null]);

        foreach ($validated['pairs'] ?? [] as $pair) {
            $pairs[$pair['order']] = [
                $pair['home_team_id'] ?? null,
                $pair['away_team_id'] ?? null,
            ];
        }

        return $pairs;
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
