<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MatchLineupResource;
use App\Http\Resources\MatchResource;
use App\Models\Event;
use App\Models\EventPersonnel;
use App\Models\GameMatch;
use App\Models\MatchLineup;
use App\Services\LineupService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a referee does with the team sheets: read both, then answer each.
 *
 * Behind `event.referee`, which is behind `event.personnel` — so, exactly like
 * OfficiatingMatchController, this file resolves everything from the event the
 * middleware put on the request and never from a tenant, because these requests
 * carry no `organization` attribute and must not start to.
 *
 * Per-match, per-team on purpose. The two sheets of one fixture are handed in by
 * two different managers at two different times, and a single verdict over both
 * would either hold up the team that was ready or approve the one that wasn't.
 *
 * There is no un-approve. The print gate in phase 7 reads `approved`, so a route
 * back would also reopen editing on a sheet that may already be on the table;
 * the fix for an approval given in error is the organizer, not a cancel button.
 */
class LineupApprovalController extends Controller
{
    public function __construct(protected LineupService $lineups) {}

    /**
     * Both sheets of one fixture, whatever state each is in.
     *
     * Rows that do not exist yet are returned as null rather than created: a
     * manager who has not opened the editor has not submitted an empty sheet,
     * and the referee's screen has to be able to say which of the two it is
     * still waiting for.
     */
    public function index(Request $request, string $event, string $match): JsonResponse
    {
        $matchModel = $this->match($request, $match);

        $lineups = MatchLineup::where('match_id', $matchModel->id)
            ->with(['players.player', 'officials.official', 'reviewer', 'team.event'])
            ->get()
            ->keyBy('team_id');

        $side = function (?string $teamId) use ($lineups) {
            if (! $teamId) {
                return null;
            }

            $lineup = $lineups->get($teamId);

            return $lineup ? new MatchLineupResource($lineup) : null;
        };

        return ApiResponse::success([
            'match' => new MatchResource($matchModel->load(['homeTeam', 'awayTeam'])),
            'event' => [
                'id' => $matchModel->event_id,
                'timezone' => $this->event($request)->timezone,
                'sport_type' => $this->event($request)->sport_type,
            ],
            'home' => $side($matchModel->home_team_id),
            'away' => $side($matchModel->away_team_id),
        ]);
    }

    /**
     * Accept one team's sheet.
     */
    public function approve(Request $request, string $event, string $lineup): JsonResponse
    {
        $model = $this->lineup($request, $lineup);

        $this->lineups->approve($model, $this->personnel($request));

        return ApiResponse::success(
            new MatchLineupResource($this->loaded($model)),
            'Susunan pemain disetujui',
        );
    }

    /**
     * Send one team's sheet back, with the reason the manager has to answer.
     */
    public function reject(Request $request, string $event, string $lineup): JsonResponse
    {
        $model = $this->lineup($request, $lineup);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ]);

        $this->lineups->reject($model, $this->personnel($request), $data['note']);

        return ApiResponse::success(
            new MatchLineupResource($this->loaded($model)),
            'Susunan pemain dikembalikan ke manajer',
        );
    }

    /**
     * A sheet belonging to a fixture of this event.
     *
     * Scoped through the match, not looked up by id: a lineup id from a sibling
     * event of the same organizer is precisely what this has to refuse, and the
     * referee's claim reaches one event only.
     */
    protected function lineup(Request $request, string $lineupId): MatchLineup
    {
        $event = $this->event($request);

        return MatchLineup::whereKey($lineupId)
            ->whereHas('match', fn ($q) => $q->where('event_id', $event->id))
            ->firstOrFail();
    }

    protected function loaded(MatchLineup $lineup): MatchLineup
    {
        return $lineup->fresh()->load(['players.player', 'officials.official', 'reviewer', 'team.event']);
    }

    /**
     * Same scope as OfficiatingMatchController::match(), for the same reason.
     */
    protected function match(Request $request, string $matchId): GameMatch
    {
        return $this->event($request)->matches()->findOrFail($matchId);
    }

    protected function event(Request $request): Event
    {
        /** @var Event $event */
        $event = $request->attributes->get('event');

        return $event;
    }

    protected function personnel(Request $request): EventPersonnel
    {
        /** @var EventPersonnel $person */
        $person = $request->attributes->get('personnel');

        return $person;
    }
}
