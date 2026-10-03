<?php

namespace App\Services;

use App\Models\EventCategory;
use App\Support\HybridConfig;
use App\Support\SwissPairing;

/**
 * The one answer to "can another Swiss round be added, and if not why not?".
 *
 * Swiss is generated a round at a time, so every surface needs the same reading
 * of the same state: the organizer's schedule page disables its button on it,
 * the endpoint refuses on it, and the progress line prints it. Written once here
 * so the button and the endpoint cannot disagree — the shape of the bug that
 * left "bracket siap dibuat" sitting above a disabled button.
 *
 * Readiness needs *two* numbers, not one. "0 pending" means two opposite things:
 * the round is over, or there is no round yet — which is why
 * `round_matches_total` is published beside `round_matches_pending` and the
 * frontend reads this payload rather than re-deriving the gate.
 */
class SwissService
{
    public function __construct(
        protected StandingService $standings,
    ) {}

    /**
     * Everything the schedule page and the endpoints read.
     *
     * @return array<string, mixed>
     */
    public function state(EventCategory $category): array
    {
        $config = HybridConfig::fromCategory($category);

        $teams = $category->teams()->where('status', 'approved')->count();
        $planned = $config->swissRoundCount($teams);

        $rounds = $category->matches()->where('stage', 'swiss')->distinct()->pluck('round');
        $created = $rounds->count();
        $last = $created > 0 ? (int) $rounds->max() : 0;

        $total = $last > 0
            ? $category->matches()->where('stage', 'swiss')->where('round', $last)->count()
            : 0;

        $pending = $last > 0
            ? $category->matches()
                ->where('stage', 'swiss')
                ->where('round', $last)
                // A bye is already finished and confirmed, so it never counts as
                // pending — but it carries no opponent, and the predicate below
                // is written to be read by humans, so it is excluded by hand.
                ->whereNotNull('home_team_id')
                ->whereNotNull('away_team_id')
                ->where(fn ($q) => $q->where('status', '!=', 'finished')->orWhereNull('confirmed_at'))
                ->count()
            : 0;

        $state = [
            'rounds_planned' => $planned,
            'rounds_created' => $created,
            'last_round' => $last,
            'round_matches_total' => $total,
            'round_matches_pending' => $pending,
            'teams' => $teams,
            'next_round' => $last + 1,
        ];

        $blocker = $this->blockerFor($category, $state);

        $state['can_add_round'] = $blocker === null;
        $state['blocked_reason'] = $blocker['message'] ?? null;

        return $state;
    }

    /**
     * Why another round cannot be added, or null when it can.
     *
     * @return array{message: string, errors: array<string, string>|null}|null
     */
    public function blocker(EventCategory $category): ?array
    {
        return $this->blockerFor($category, $this->state($category));
    }

    /**
     * The order the next round is paired from.
     *
     * Round 1 has no table to read, so it is a shuffle of the approved entrants
     * — seeding a first Swiss round off the alphabet would hand the same two
     * halves of the draw to each other in every event.
     *
     * From round 2 it is the standings, which is the whole format: this is also
     * why SwissService depends on StandingService and ScheduleService does not.
     *
     * @return array<int, string>
     */
    public function pairingOrder(EventCategory $category): array
    {
        $played = $category->matches()->where('stage', 'swiss')->exists();

        if (! $played) {
            return $category->teams()
                ->where('status', 'approved')
                ->pluck('id')
                ->shuffle()
                ->all();
        }

        return array_values(array_filter(array_map(
            fn (array $row) => $row['team']['id'] ?? null,
            $this->standings->compute($category),
        )));
    }

    /**
     * The blocker, derived from an already-computed state so `state()` does not
     * read the category twice.
     *
     * The order of the checks is the order the organizer can act on them: no
     * field, then no first round, then an unfinished round, then a plan that is
     * used up, and only last the one nobody can fix — a field where everybody
     * has already met everybody.
     *
     * @param  array<string, mixed>  $state
     * @return array{message: string, errors: array<string, string>|null}|null
     */
    protected function blockerFor(EventCategory $category, array $state): ?array
    {
        if ($state['teams'] < 2) {
            return ['message' => 'Butuh minimal 2 tim yang disetujui untuk membuat jadwal.', 'errors' => null];
        }

        if ($state['rounds_created'] === 0) {
            return [
                'message' => 'Belum ada ronde Swiss — buat jadwal ronde 1 dulu sebelum menambah ronde.',
                'errors' => ['feature' => 'swiss_round_incomplete'],
            ];
        }

        if ($state['round_matches_pending'] > 0) {
            return [
                'message' => "Masih ada {$state['round_matches_pending']} pertandingan ronde {$state['last_round']} yang belum selesai/dikonfirmasi.",
                'errors' => ['feature' => 'swiss_round_incomplete'],
            ];
        }

        if ($state['rounds_created'] >= $state['rounds_planned']) {
            return [
                'message' => "Jumlah ronde yang direncanakan ({$state['rounds_planned']}) sudah terpakai.",
                'errors' => ['feature' => 'swiss_rounds'],
            ];
        }

        if ($this->exhausted($category, $state)) {
            return [
                'message' => 'Semua tim sudah pernah bertemu — tidak ada pasangan baru untuk ronde berikutnya.',
                'errors' => ['feature' => 'swiss_exhausted'],
            ];
        }

        return null;
    }

    /**
     * Whether the field has run out of unplayed pairings.
     *
     * Asked of the pairer rather than counted, because "every pair has met" and
     * "every pair the pairer can still reach has met" are not the same question
     * once a bye is taken out of the order, and the pairer is the one that
     * decides. It reports repeats instead of throwing, so a non-zero count is
     * exactly the exhausted case.
     *
     * @param  array<string, mixed>  $state
     */
    protected function exhausted(EventCategory $category, array $state): bool
    {
        $order = $this->pairingOrder($category);

        $byeCounts = $category->matches()
            ->where('stage', 'swiss')
            ->whereNotNull('home_team_id')
            ->whereNull('away_team_id')
            ->where('status', '!=', 'cancelled')
            ->get(['home_team_id'])
            ->groupBy('home_team_id')
            ->map->count()
            ->all();

        $bye = SwissPairing::byeTeam($order, $byeCounts);

        if ($bye !== null) {
            $order = array_values(array_diff($order, [$bye]));
        }

        if (count($order) < 2) {
            return false;
        }

        $met = [];
        foreach ($category->matches()
            ->where(fn ($q) => $q->whereNull('stage')->orWhere('stage', 'swiss'))
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id')
            ->get(['home_team_id', 'away_team_id']) as $match) {
            $met[SwissPairing::pairKey($match->home_team_id, $match->away_team_id)] = true;
        }

        return SwissPairing::pair($order, $met)['rematches'] > 0;
    }
}
