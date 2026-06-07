<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MatchResource;
use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Services\PlayerStatService;
use App\Services\ScheduleService;
use App\Services\StandingService;
use App\Support\ApiResponse;
use App\Support\SportStats;
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
        protected PlayerStatService $stats,
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
     * Sport-aware player leaderboard for an event.
     */
    public function leaderboard(Request $request, string $organization, string $event): JsonResponse
    {
        $eventModel = $this->event($request, $event);

        return ApiResponse::success($this->stats->leaderboard($eventModel));
    }

    /**
     * Rosters of both teams, the stat columns for the sport, and the current
     * per-player tally — everything the match stat editor needs.
     */
    public function matchStats(Request $request, string $organization, string $match): JsonResponse
    {
        $matchModel = $this->match($request, $match)->load(['homeTeam.players', 'awayTeam.players', 'stats']);

        // player_id => { stat_key => value }
        $current = $matchModel->stats
            ->groupBy('player_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($s) => [$s->stat_key => $s->value]));

        return ApiResponse::success([
            'columns' => SportStats::columns($matchModel->event->sport_type),
            'home_team' => $this->teamRoster($matchModel->homeTeam),
            'away_team' => $this->teamRoster($matchModel->awayTeam),
            'stats' => $current,
        ]);
    }

    /**
     * Replace the player stats for a match.
     */
    public function saveMatchStats(Request $request, string $organization, string $match): JsonResponse
    {
        $matchModel = $this->match($request, $match)->load(['homeTeam.players', 'awayTeam.players']);
        $allowedKeys = SportStats::keys($matchModel->event->sport_type);

        $validated = $request->validate([
            'stats' => ['present', 'array'],
            'stats.*.player_id' => ['required', 'uuid'],
            'stats.*.stat_key' => ['required', 'string', Rule::in($allowedKeys)],
            'stats.*.value' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        // player_id => team_id, restricted to the two teams on the pitch.
        $roster = collect([$matchModel->homeTeam, $matchModel->awayTeam])
            ->filter()
            ->flatMap(fn ($team) => $team->players->map(fn ($p) => ['player_id' => $p->id, 'team_id' => $team->id]))
            ->keyBy('player_id');

        $matchModel->stats()->delete();

        foreach ($validated['stats'] as $entry) {
            if ($entry['value'] < 1 || ! $roster->has($entry['player_id'])) {
                continue;
            }

            $matchModel->stats()->create([
                'team_id' => $roster[$entry['player_id']]['team_id'],
                'player_id' => $entry['player_id'],
                'stat_key' => $entry['stat_key'],
                'value' => $entry['value'],
            ]);
        }

        return ApiResponse::success(null, 'Statistik pemain disimpan');
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

    /**
     * @param  \App\Models\Team|null  $team
     * @return array<string, mixed>|null
     */
    protected function teamRoster($team): ?array
    {
        if (! $team) {
            return null;
        }

        return [
            'id' => $team->id,
            'name' => $team->name,
            'players' => $team->players->map(fn ($p) => [
                'id' => $p->id,
                'full_name' => $p->full_name,
                'jersey_number' => $p->jersey_number,
            ])->values(),
        ];
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
