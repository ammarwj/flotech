<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AppliesMatchClock;
use App\Http\Controllers\Controller;
use App\Http\Resources\MatchResource;
use App\Models\Event;
use App\Models\GameMatch;
use App\Services\MatchResultService;
use App\Services\MatchStatService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What match staff write: the scoreline, and the per-player stats behind it.
 *
 * Every route here sits behind `event.staff`, which sits behind
 * `event.personnel`. There is no `organization` attribute on these requests and
 * there must never be one, so this controller resolves its fixture from the
 * event the middleware put on the request — never from the tenant. That is the
 * one structural difference from MatchController; everything else it does, it
 * does by calling the same two services the organizer's door calls.
 *
 * The rule that is not shared, and must not be:
 *
 *   An operator records; they don't ratify.
 *
 * MatchController hands `$autoConfirm = true` when the person saving
 * administers the organization. Nobody here ever does — a task account holds no
 * organization_members row, by design. So this door passes `false`
 * unconditionally rather than asking a question whose answer is already known,
 * and the result waits for `PATCH matches/{match}/confirm` behind `org.admin`.
 */
class OfficiatingMatchController extends Controller
{
    use AppliesMatchClock;

    public function __construct(
        protected MatchResultService $results,
        protected MatchStatService $statSheet,
    ) {}

    /**
     * Rosters of both teams, the stat columns for the sport, and the current
     * per-player tally — the same payload the organizer's editor reads, from
     * the same method, so the two editors cannot offer different columns.
     */
    public function matchStats(Request $request, string $event, string $match): JsonResponse
    {
        return ApiResponse::success($this->statSheet->snapshot($this->match($request, $match)));
    }

    /**
     * Replace the player stats for a match: goals, assists, yellows, reds.
     *
     * Cards are whatever the sport's catalog says carries the `yellow`/`red`
     * role — this surface has no idea which key that is, and that is the point
     * (see the discipline invariant in CLAUDE.md).
     */
    public function saveMatchStats(Request $request, string $event, string $match): JsonResponse
    {
        $matchModel = $this->match($request, $match);

        $validated = $request->validate($this->statSheet->rules($matchModel));

        if ($error = $this->statSheet->replace($matchModel, $validated['stats'])) {
            return ApiResponse::error($error, ['assists' => [$error]], 422);
        }

        return ApiResponse::success(null, 'Statistik pemain disimpan');
    }

    /**
     * Record a match result (scores + status). Never confirms it.
     */
    public function updateResult(Request $request, string $event, string $match): JsonResponse
    {
        $matchModel = $this->match($request, $match);

        $payload = $this->results->payloadFrom($request, $matchModel);

        $this->results->apply($matchModel, $payload, false);

        return ApiResponse::success(
            new MatchResource($matchModel->fresh()->load(['homeTeam', 'awayTeam'])),
            $payload['status'] === 'finished'
                ? 'Hasil disimpan — menunggu konfirmasi admin'
                : 'Pertandingan diperbarui',
        );
    }

    /**
     * Jam pertandingan, dari pinggir lapangan.
     *
     * Ini justru pintu utamanya: yang memegang stopwatch hampir selalu akun
     * petugas, bukan admin organisasi. Tidak seperti hasil pertandingan, jam
     * tidak punya langkah "menunggu konfirmasi admin" — ia presentasi, dan
     * papan skor yang menunggu ratifikasi tidak ada gunanya bagi siapa pun.
     */
    public function updateClock(Request $request, string $event, string $match): JsonResponse
    {
        return $this->applyClock($request, $this->match($request, $match));
    }

    /**
     * Resolve a fixture inside the event this caller is crew on.
     *
     * Scoped through `event_id` rather than the organization: the crew's claim
     * is to one event, and a match id from a sibling event of the same
     * organizer is exactly the thing this scope has to refuse.
     */
    protected function match(Request $request, string $matchId): GameMatch
    {
        /** @var Event $event */
        $event = $request->attributes->get('event');

        return $event->matches()->findOrFail($matchId);
    }
}
