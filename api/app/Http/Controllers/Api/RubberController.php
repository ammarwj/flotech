<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MatchResource;
use App\Http\Resources\RubberResource;
use App\Models\GameMatch;
use App\Models\MatchRubber;
use App\Models\Organization;
use App\Models\Player;
use App\Services\MatchResultService;
use App\Services\RubberService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The partai of a squad-vs-squad tie.
 *
 * "Spanyol 3-0 Argentina" is not typed in — it is the count of partai won, so
 * every write here rolls the tie back up through MatchResultService, which is
 * also what carries a knockout winner into the next bracket slot.
 */
class RubberController extends Controller
{
    public function __construct(
        protected RubberService $rubbers,
        protected MatchResultService $results,
    ) {}

    public function index(Request $request, string $organization, string $match): JsonResponse
    {
        $matchModel = $this->match($request, $match);

        return ApiResponse::success(RubberResource::collection($matchModel->rubbers));
    }

    /**
     * Replace the tie's partai list in one go — the category template is only a
     * starting point, and a tie may add a decider or drop a walkover.
     *
     * Same sync contract as the roster: a row with an `id` is an update, one
     * without is new, and anything left out is deleted. The `rubbers.*.id` rule
     * is load-bearing for exactly the reason spelled out in RegisterTeamRequest
     * — without it validated() strips every id, all partai are recreated, and
     * the scores already recorded against them are gone.
     */
    public function sync(Request $request, string $organization, string $match): JsonResponse
    {
        $matchModel = $this->match($request, $match);

        if ($error = $this->guard($matchModel)) {
            return $error;
        }

        $data = $request->validate([
            'rubbers' => ['present', 'array', 'max:12'],
            'rubbers.*.id' => ['nullable', 'uuid'],
            'rubbers.*.label' => ['required', 'string', 'max:60'],
            'rubbers.*.type' => ['required', Rule::in(array_keys(MatchRubber::TYPES))],
        ]);

        $keep = [];

        foreach (array_values($data['rubbers']) as $order => $row) {
            $attributes = ['order' => $order, 'label' => $row['label'], 'type' => $row['type']];

            $existing = ! empty($row['id']) ? $matchModel->rubbers()->find($row['id']) : null;

            if ($existing) {
                // Changing single↔double invalidates a lineup sized for the old
                // shape; the score it produced stands until someone re-enters it.
                if ($existing->type !== $row['type']) {
                    $attributes['home_player_ids'] = null;
                    $attributes['away_player_ids'] = null;
                }

                $existing->update($attributes);
                $keep[] = $existing->id;
            } else {
                $keep[] = $matchModel->rubbers()->create($attributes)->id;
            }
        }

        $matchModel->rubbers()->whereKeyNot($keep)->delete();

        return $this->rollUp($request, $matchModel, 'Daftar partai diperbarui');
    }

    /**
     * Record one partai: who played it and the set scores.
     */
    public function update(Request $request, string $organization, string $rubber): JsonResponse
    {
        $rubberModel = $this->rubber($request, $rubber);
        $matchModel = $rubberModel->match;

        if ($error = $this->guard($matchModel)) {
            return $error;
        }

        $data = $request->validate([
            'home_player_ids' => ['nullable', 'array'],
            'home_player_ids.*' => ['uuid'],
            'away_player_ids' => ['nullable', 'array'],
            'away_player_ids.*' => ['uuid'],
            'sets' => ['nullable', 'array', 'max:7'],
            'sets.*.home' => ['required', 'integer', 'min:0', 'max:99'],
            'sets.*.away' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        foreach (['home' => $matchModel->home_team_id, 'away' => $matchModel->away_team_id] as $side => $teamId) {
            $key = "{$side}_player_ids";

            if (! array_key_exists($key, $data)) {
                continue;
            }

            if ($error = $this->validateLineup($rubberModel, $data[$key] ?? [], $teamId, $key)) {
                return $error;
            }

            $rubberModel->{$key} = $data[$key] ?: null;
        }

        $rubberModel->save();
        $this->rubbers->applySets($rubberModel, $data['sets'] ?? null);

        return $this->rollUp($request, $matchModel, 'Skor partai disimpan');
    }

    /**
     * A lineup is drawn from that side's own roster and is the size the partai
     * calls for — one player for tunggal, two for ganda. A half-filled lineup is
     * allowed (the organizer may not know it yet); a wrong-sized full one is not.
     *
     * @param  array<int, string>  $playerIds
     */
    protected function validateLineup(MatchRubber $rubber, array $playerIds, ?string $teamId, string $key): ?JsonResponse
    {
        if ($playerIds === []) {
            return null;
        }

        if (count($playerIds) !== $rubber->lineupSize() || count(array_unique($playerIds)) !== count($playerIds)) {
            return ApiResponse::error(
                $rubber->type === 'single'
                    ? 'Partai tunggal diisi 1 pemain.'
                    : 'Partai ganda diisi 2 pemain berbeda.',
                [$key => ['Jumlah pemain tidak sesuai.']],
                422,
            );
        }

        $owned = Player::where('team_id', $teamId)->whereKey($playerIds)->count();

        if ($owned !== count($playerIds)) {
            return ApiResponse::error(
                'Pemain harus berasal dari tim yang bertanding.',
                [$key => ['Pemain bukan anggota tim ini.']],
                422,
            );
        }

        return null;
    }

    /**
     * Recompute the tie from its partai and write it as the match result.
     *
     * The tie is finished once every partai has been played; until then it is
     * whatever the schedule said it was, with a running scoreline. Auto-confirm
     * follows the same rule as a typed-in result: an org admin signs off by
     * saving, an operator's entry waits for one who can.
     */
    protected function rollUp(Request $request, GameMatch $match, string $message): JsonResponse
    {
        $rubbers = $match->rubbers()->get();
        $tally = $this->rubbers->tally($rubbers);

        $complete = $rubbers->isNotEmpty() && $rubbers->every(fn (MatchRubber $r) => $r->isPlayed());
        $status = $complete ? 'finished' : ($match->status === 'finished' ? 'ongoing' : $match->status);

        // A league tie may legitimately end level, so a draw still counts. A
        // knockout tie may not: with no winner there is nobody to seat in the
        // next round, so it waits for the organizer to settle it.
        $decided = $tally['home'] !== $tally['away'] || ! $this->results->isKnockoutTie($match);

        $confirm = $complete
            && $decided
            && $this->org($request)->isAdministeredBy($request->user());

        $this->results->apply($match, [
            'status' => $status,
            'home_score' => $tally['home'],
            'away_score' => $tally['away'],
            // A tie has no single run of sets — each partai keeps its own.
            'sets' => null,
        ], $confirm);

        return ApiResponse::success([
            'match' => new MatchResource($match->fresh()->load(['homeTeam', 'awayTeam', 'rubbers'])),
            'rubbers' => RubberResource::collection($match->rubbers()->get()),
        ], $message);
    }

    /** Partai only exist for a squad tie, and a final result is edited by unconfirming it first. */
    protected function guard(GameMatch $match): ?JsonResponse
    {
        if (! $match->category->usesRubbers()) {
            return ApiResponse::error('Kategori ini tidak memakai format partai.', null, 422);
        }

        if ($match->isConfirmed()) {
            return ApiResponse::error('Hasil sudah dikonfirmasi — batalkan konfirmasi sebelum mengubah partai.', null, 422);
        }

        return null;
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }

    protected function match(Request $request, string $matchId): GameMatch
    {
        return GameMatch::whereHas('event', fn ($q) => $q->where('organization_id', $this->org($request)->id))
            ->findOrFail($matchId);
    }

    protected function rubber(Request $request, string $rubberId): MatchRubber
    {
        return MatchRubber::whereHas(
            'match.event',
            fn ($q) => $q->where('organization_id', $this->org($request)->id),
        )->findOrFail($rubberId);
    }
}
