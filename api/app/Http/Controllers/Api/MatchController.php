<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MatchResource;
use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Services\ScheduleService;
use App\Services\StandingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Organizer-facing schedule, results, and standings for an event.
 * Routes live under organizations/{organization}, so every action declares the
 * path params positionally.
 */
class MatchController extends Controller
{
    public function __construct(
        protected ScheduleService $schedule,
        protected StandingService $standings,
    ) {}

    /**
     * (Re)generate the round-robin schedule for the event's approved teams.
     */
    public function generate(Request $request, string $organization, string $event): JsonResponse
    {
        $eventModel = $this->event($request, $event);
        $format = $eventModel->tournament_format;

        if (in_array($format, ['league', 'hybrid'], true)) {
            $count = $this->schedule->generateRoundRobin($eventModel);
        } elseif ($format === 'knockout_single') {
            $count = $this->schedule->generateKnockout($eventModel);
        } else {
            return ApiResponse::error('Pembuatan jadwal otomatis mendukung format Liga dan Knockout.', ['feature' => 'schedule_format'], 422);
        }

        if ($count === 0) {
            return ApiResponse::error('Butuh minimal 2 tim yang disetujui untuk membuat jadwal.', null, 422);
        }

        return ApiResponse::success(
            MatchResource::collection($this->orderedMatches($eventModel)),
            "Jadwal dibuat: {$count} pertandingan",
            201,
        );
    }

    /**
     * List all matches for an event (organizer view).
     */
    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $eventModel = $this->event($request, $event);

        return ApiResponse::success(MatchResource::collection($this->orderedMatches($eventModel)));
    }

    /**
     * Current league standings for an event.
     */
    public function standings(Request $request, string $organization, string $event): JsonResponse
    {
        $eventModel = $this->event($request, $event);

        return ApiResponse::success($this->standings->compute($eventModel));
    }

    /**
     * Record a match result (scores + status).
     */
    public function updateResult(Request $request, string $organization, string $match): JsonResponse
    {
        $matchModel = $this->match($request, $match);
        $eventModel = $matchModel->event;

        $validated = $request->validate([
            'home_score' => ['nullable', 'integer', 'min:0', 'max:999'],
            'away_score' => ['nullable', 'integer', 'min:0', 'max:999'],
            'status' => ['required', Rule::in(['scheduled', 'ongoing', 'finished', 'cancelled'])],
            'scheduled_at' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
        ]);

        $finished = $validated['status'] === 'finished';

        if ($finished && ($validated['home_score'] === null || $validated['away_score'] === null)) {
            return ApiResponse::error('Skor kedua tim wajib diisi untuk menyelesaikan pertandingan.', [
                'home_score' => ['Skor wajib diisi.'],
            ], 422);
        }

        $isKnockout = $eventModel->tournament_format === 'knockout_single';

        if ($finished && $isKnockout && $validated['home_score'] === $validated['away_score']) {
            return ApiResponse::error('Pertandingan knockout tidak boleh berakhir seri — tentukan pemenangnya.', null, 422);
        }

        $matchModel->update($validated);

        // Knockout: push the winner into the next round.
        if ($finished && $isKnockout) {
            $this->schedule->advanceWinner($matchModel->fresh());
        }

        return ApiResponse::success(
            new MatchResource($matchModel->load(['homeTeam', 'awayTeam'])),
            'Hasil pertandingan disimpan',
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, GameMatch>
     */
    protected function orderedMatches(Event $event)
    {
        return $event->matches()
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('round')
            ->orderBy('order')
            ->get();
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }

    protected function event(Request $request, string $eventId): Event
    {
        return $this->org($request)->events()->findOrFail($eventId);
    }

    /**
     * Resolve a match scoped to an event owned by the current organization.
     */
    protected function match(Request $request, string $matchId): GameMatch
    {
        return GameMatch::whereHas('event', fn ($q) => $q->where('organization_id', $this->org($request)->id))
            ->findOrFail($matchId);
    }
}
