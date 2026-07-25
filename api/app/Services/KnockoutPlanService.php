<?php

namespace App\Services;

use App\Models\EventCategory;
use App\Support\HybridConfig;
use App\Support\KnockoutPlan;

/**
 * The knockout bracket as *planned*: which qualifier slot meets which in the
 * opening round, with whoever currently holds each slot.
 *
 * One door, two callers: the organizer's plan view and the activation that turns
 * the plan into real fixtures. If activation built its own reading of the stored
 * plan it would eventually disagree with the one on screen — and the organizer
 * would only find out after the bracket existed.
 *
 * Deliberately not reachable from the public event page: a draw the organizer
 * can still rearrange is a working document, and publishing it would commit
 * them to pairings in front of every entrant. Spectators get the bracket once
 * it is generated.
 *
 * A category with no stored plan comes back with an *empty* draw — the shape of
 * the bracket, none of the pairings. Automatic seeding still exists and still
 * runs, but only at generation time ({@see ScheduleService::firstRoundPairs()}):
 * showing its result here would put a draw on screen that nobody chose and that
 * a later re-read could quietly change.
 */
class KnockoutPlanService
{
    public function __construct(
        protected StandingService $standings,
    ) {}

    /**
     * Everything the plan view (organizer or public) renders.
     *
     * @return array<string, mixed>
     */
    public function build(EventCategory $category): array
    {
        $size = HybridConfig::fromCategory($category)->bracketSize();
        $half = intdiv($size, 2);
        $slots = $this->standings->qualifierSlots($category);
        $bySlotKey = array_column($slots, null, 'key');

        $resolved = $this->resolved($category, $slots, $half);

        // An empty draw when nothing has been saved — deliberately *not* the
        // automatic seeding. A suggestion sitting in the slots reads as a
        // decision already made, and the organizer's own draw is the point of
        // the whole feature. Automatic seeding is still there; it just doesn't
        // happen until they generate the bracket without a plan.
        $ties = array_map(fn (array $tie) => [
            'order' => $tie['order'],
            'home' => $tie['home'] !== null ? ($bySlotKey[$tie['home']] ?? null) : null,
            'away' => $tie['away'] !== null ? ($bySlotKey[$tie['away']] ?? null) : null,
            'scheduled_at' => $tie['scheduled_at'],
            'venue' => $tie['venue'],
        ], $this->padded($resolved['ties'] ?? [], $half));

        $pending = $category->matches()
            ->where('stage', 'group')
            ->where(fn ($q) => $q->where('status', '!=', 'finished')->orWhereNull('confirmed_at'))
            ->count();

        return [
            'bracket_size' => $size,
            'qualifiers' => count($slots),
            'byes' => max(0, $size - count($slots)),
            // Sent alongside the pending count because zero pending means two
            // opposite things: every group game is played, or none exists yet.
            // generateKnockout() refuses the second, so the client needs to be
            // able to tell them apart or it offers a button that cannot work.
            'group_matches_total' => $category->matches()->where('stage', 'group')->count(),
            'group_matches_pending' => $pending,
            'source' => $resolved !== null ? 'manual' : 'auto',
            // A stored plan that outlived the config it was drawn against. Said
            // out loud rather than silently repaired, because only the organizer
            // knows where the orphaned slots should go now.
            'stale' => $resolved['stale'] ?? false,
            'unplaced_slots' => array_values(array_map(
                fn (string $key) => $bySlotKey[$key],
                $resolved['unplaced'] ?? [],
            )),
            'slots' => $slots,
            'ties' => $ties,
        ];
    }

    /**
     * The first-round slots activation should use, or null when the category has
     * no stored plan and automatic seeding should take over.
     *
     * @return array<int, array{home: ?string, away: ?string, scheduled_at: ?string, venue: ?string}>|null
     */
    public function pairsFor(EventCategory $category): ?array
    {
        $half = intdiv(HybridConfig::fromCategory($category)->bracketSize(), 2);
        $slots = $this->standings->qualifierSlots($category);
        $resolved = $this->resolved($category, $slots, $half);

        return $resolved === null
            ? null
            : KnockoutPlan::toPairs($resolved['ties'], $slots, $half);
    }

    /**
     * Why a stored plan can't be activated yet, or an empty array when it can
     * (including when there is no plan at all).
     *
     * @return array{stale: bool, unplaced: array<int, string>}|array{}
     */
    public function blockers(EventCategory $category): array
    {
        $half = intdiv(HybridConfig::fromCategory($category)->bracketSize(), 2);
        $slots = $this->standings->qualifierSlots($category);
        $resolved = $this->resolved($category, $slots, $half);

        if ($resolved === null || (! $resolved['stale'] && $resolved['unplaced'] === [])) {
            return [];
        }

        return [
            'stale' => $resolved['stale'],
            // Labels, not keys: this ends up in a message the organizer reads.
            'unplaced' => array_map(
                fn (string $key) => array_column($slots, 'label', 'key')[$key] ?? $key,
                $resolved['unplaced'],
            ),
        ];
    }

    /**
     * The stored plan reconciled against today's slots, or null when there is
     * none.
     *
     * @param  array<int, array<string, mixed>>  $slots
     * @return array{ties: array<int, array<string, mixed>>, stale: bool, unplaced: array<int, string>}|null
     */
    protected function resolved(EventCategory $category, array $slots, int $half): ?array
    {
        $stored = $category->knockout_plan;

        return is_array($stored) && $stored !== []
            ? KnockoutPlan::resolve($stored, $slots, $half)
            : null;
    }

    /**
     * Ties for every slot of the bracket, so the view draws the whole draw and
     * not only the ties the organizer filled in.
     *
     * @param  array<int, array<string, mixed>>  $ties
     * @return array<int, array<string, mixed>>
     */
    protected function padded(array $ties, int $half): array
    {
        $byOrder = array_column($ties, null, 'order');

        return array_map(fn (int $order) => $byOrder[$order] ?? [
            'order' => $order,
            'home' => null,
            'away' => null,
            'scheduled_at' => null,
            'venue' => null,
        ], range(0, max(0, $half - 1)));
    }
}
