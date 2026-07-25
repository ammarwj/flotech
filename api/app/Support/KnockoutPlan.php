<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * The first knockout round drawn in *slots* instead of teams: "Juara Grup A" v
 * "Runner-up Grup B", decided while the groups are still being played.
 *
 * The sibling of {@see BracketSeeding}, kept apart on purpose. That one pairs
 * team uuids and is read once, at the moment the bracket is generated; this one
 * pairs slot keys ("A1", "BR2") and is *stored*, so it has to survive the
 * standings moving underneath it and the organizer changing the qualification
 * rules afterwards. resolve() is where that difference lives, and there is no
 * equivalent of it on the seeding side.
 *
 * The two meet at toPairs(), which reads today's occupant of every planned slot
 * and hands ScheduleService the very same slot shape BracketSeeding produces —
 * so generation never learns that a plan exists.
 *
 * Stored shape (one row per first-round tie, order-indexed):
 *   [{"order": 0, "home": "A1", "away": "B2", "scheduled_at": null, "venue": null}]
 *
 * @phpstan-type Tie array{order: int, home: ?string, away: ?string, scheduled_at: ?string, venue: ?string}
 * @phpstan-type Slot array{key: string, label: string, group: ?string, place: int, team: ?array<string, mixed>}
 */
class KnockoutPlan
{
    /**
     * Rules for a PUT knockout-plan payload.
     *
     * @param  int  $halfSize  first-round slots (bracket size / 2)
     * @param  array<int, string>  $slotKeys  qualifier slots that may be placed
     * @return array<string, mixed>
     */
    public static function validationRules(int $halfSize, array $slotKeys): array
    {
        return [
            'ties' => ['required', 'array', 'max:'.$halfSize],
            'ties.*.order' => ['required', 'integer', 'min:0', 'max:'.max(0, $halfSize - 1)],
            // Both sides optional: a tie may be left empty, or carry a lone slot
            // (a bye), or nothing but a kickoff and a venue.
            'ties.*.home' => ['nullable', 'string', Rule::in($slotKeys)],
            'ties.*.away' => ['nullable', 'string', 'different:ties.*.home', Rule::in($slotKeys)],
            // Pre-booked kickoff/court for a tie whose teams aren't known yet.
            'ties.*.scheduled_at' => ['nullable', 'date'],
            'ties.*.venue' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The validated payload as the stored shape: one row per tie, sorted by
     * order, empty ties dropped.
     *
     * A lone slot is moved to the home side for the same reason
     * {@see BracketSeeding::normalize()} does it — an away side with nobody at
     * home is not a bye, so nothing ever advances out of it and no opponent
     * arrives to make it a real tie.
     *
     * @param  array<string, mixed>  $validated
     * @return array<int, Tie>
     */
    public static function normalize(array $validated, int $halfSize): array
    {
        $ties = [];

        foreach ($validated['ties'] ?? [] as $tie) {
            $order = (int) $tie['order'];

            if ($order >= $halfSize) {
                continue;
            }

            $home = $tie['home'] ?? null;
            $away = $tie['away'] ?? null;

            if ($home === null && $away !== null) {
                [$home, $away] = [$away, null];
            }

            $scheduledAt = $tie['scheduled_at'] ?? null;
            $venue = $tie['venue'] ?? null;

            // A row that says nothing is not a plan for that slot; storing it
            // would only make resolve() report an empty tie as deliberate.
            if ($home === null && $away === null && $scheduledAt === null && $venue === null) {
                continue;
            }

            $ties[$order] = [
                'order' => $order,
                'home' => $home,
                'away' => $away,
                'scheduled_at' => $scheduledAt,
                'venue' => $venue,
            ];
        }

        ksort($ties);

        return array_values($ties);
    }

    /**
     * Reconcile a stored plan with the qualifier slots that exist *now*.
     *
     * The plan outlives the config that produced it: dropping a group or
     * lowering `top_per_group` deletes slots the plan still names, and raising
     * either one invents slots it never placed. Both are the organizer's to fix
     * — this only reports them, so nothing is thrown away behind their back.
     *
     * @param  array<int, mixed>  $stored  the raw `knockout_plan` column
     * @param  array<int, Slot>  $slots  qualifier slots, best seed first
     * @return array{ties: array<int, Tie>, stale: bool, unplaced: array<int, string>}
     */
    public static function resolve($stored, array $slots, int $halfSize): array
    {
        $known = array_column($slots, 'key');
        $stale = false;
        $ties = [];
        $placed = [];

        foreach (is_array($stored) ? $stored : [] as $tie) {
            if (! is_array($tie) || ! isset($tie['order'])) {
                continue;
            }

            $order = (int) $tie['order'];

            // The bracket shrank under the plan; those ties have nowhere to go.
            if ($order >= $halfSize) {
                $stale = true;

                continue;
            }

            $sides = [];
            foreach (['home', 'away'] as $side) {
                $key = $tie[$side] ?? null;

                if ($key !== null && ! in_array($key, $known, true)) {
                    $stale = true; // the slot this tie named no longer exists
                    $key = null;
                }

                if ($key !== null) {
                    $placed[$key] = true;
                }

                $sides[$side] = $key;
            }

            // Losing the home side to a stale key leaves the away side stranded
            // — same shape normalize() refuses, so repair it here too.
            if ($sides['home'] === null && $sides['away'] !== null) {
                [$sides['home'], $sides['away']] = [$sides['away'], null];
            }

            $ties[$order] = [
                'order' => $order,
                'home' => $sides['home'],
                'away' => $sides['away'],
                'scheduled_at' => $tie['scheduled_at'] ?? null,
                'venue' => $tie['venue'] ?? null,
            ];
        }

        ksort($ties);

        return [
            'ties' => array_values($ties),
            'stale' => $stale,
            'unplaced' => array_values(array_filter($known, fn ($key) => ! isset($placed[$key]))),
        ];
    }

    /**
     * Fill in every first-round slot from a resolved plan, in the shape
     * ScheduleService writes matches from.
     *
     * A planned slot with nobody in it yet (an empty group table, a "best third"
     * that never materialised) comes out null, which generation already reads as
     * an empty side — the same as a slot the organizer left blank.
     *
     * @param  array<int, Tie>  $ties  from resolve()
     * @param  array<int, Slot>  $slots
     * @return array<int, array{home: ?string, away: ?string, scheduled_at: ?string, venue: ?string}>
     */
    public static function toPairs(array $ties, array $slots, int $halfSize): array
    {
        $occupant = [];
        foreach ($slots as $slot) {
            $occupant[$slot['key']] = $slot['team']['id'] ?? null;
        }

        $pairs = array_fill(0, max(1, $halfSize), BracketSeeding::slot(null, null));

        foreach ($ties as $tie) {
            $home = $tie['home'] !== null ? ($occupant[$tie['home']] ?? null) : null;
            $away = $tie['away'] !== null ? ($occupant[$tie['away']] ?? null) : null;

            // The planned home slot may be the empty one; a lone team still has
            // to sit at home to read as a bye.
            if ($home === null && $away !== null) {
                [$home, $away] = [$away, null];
            }

            $pairs[$tie['order']] = BracketSeeding::slot(
                $home,
                $away,
                $tie['scheduled_at'] ?? null,
                $tie['venue'] ?? null,
            );
        }

        return $pairs;
    }

    /**
     * The first slot key placed in two ties, or null when every pick is
     * distinct. Rule::in cannot see across ties, and `distinct` cannot reach two
     * sibling keys of the same row — the same gap {@see
     * BracketSeeding::duplicateTeam()} fills.
     *
     * @param  array<int, array<string, mixed>>  $ties  the raw validated ties
     */
    public static function duplicateSlot(array $ties): ?string
    {
        $seen = [];

        foreach ($ties as $tie) {
            foreach ([$tie['home'] ?? null, $tie['away'] ?? null] as $key) {
                if ($key === null) {
                    continue;
                }
                if (isset($seen[$key])) {
                    return $key;
                }
                $seen[$key] = true;
            }
        }

        return null;
    }

    /**
     * Qualifier slots the organizer placed nowhere.
     *
     * Leaving one side of a *tie* empty is deliberate (that is a bye); leaving a
     * qualifier slot out of the draw entirely is not — whoever wins that place
     * would qualify and then never play. Same distinction as {@see
     * BracketSeeding::unplacedTeams()}.
     *
     * @param  array<int, array<string, mixed>>  $ties  the raw validated ties
     * @param  array<int, string>  $slotKeys
     * @return array<int, string> unplaced slot keys, in seed order
     */
    public static function unplacedSlots(array $ties, array $slotKeys): array
    {
        $placed = [];

        foreach ($ties as $tie) {
            foreach ([$tie['home'] ?? null, $tie['away'] ?? null] as $key) {
                if ($key !== null) {
                    $placed[$key] = true;
                }
            }
        }

        return array_values(array_filter($slotKeys, fn ($key) => ! isset($placed[$key])));
    }
}
