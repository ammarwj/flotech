<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MatchResource;
use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Team;
use App\Services\PlayerStatService;
use App\Services\ScheduleService;
use App\Services\StandingService;
use App\Support\ApiResponse;
use App\Support\MatchScoring;
use App\Support\SportStats;
use Illuminate\Database\Eloquent\Collection;
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

        $options = $request->validate([
            'start_date' => ['nullable', 'date'],
            'daily_start' => ['nullable', 'date_format:H:i'],
            'daily_end' => ['nullable', 'date_format:H:i'],
            'match_minutes' => ['nullable', 'integer', 'min:10', 'max:600'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'venues' => ['nullable', 'integer', 'min:1', 'max:20'],
            'max_per_day' => ['nullable', 'integer', 'min:1', 'max:200'],
            'spread' => ['nullable', 'boolean'],
        ]);

        if (! empty($options['daily_start']) && ! empty($options['daily_end'])
            && $options['daily_end'] <= $options['daily_start']) {
            return ApiResponse::error('Jam selesai harus setelah jam mulai.', [
                'daily_end' => ['Jam selesai harus setelah jam mulai.'],
            ], 422);
        }

        if (in_array($format, ['league', 'hybrid'], true)) {
            $count = $this->schedule->generateRoundRobin($eventModel);
        } elseif ($format === 'knockout_single') {
            $count = $this->schedule->generateKnockout($eventModel);
        } elseif ($format === 'knockout_double') {
            $count = $this->schedule->generateDoubleElim($eventModel);
            if ($count === -1) {
                return ApiResponse::error('Double elimination butuh jumlah tim kelipatan dua (4, 8, 16, …).', ['feature' => 'schedule_format'], 422);
            }
        } else {
            return ApiResponse::error('Pembuatan jadwal otomatis mendukung format Liga dan Knockout.', ['feature' => 'schedule_format'], 422);
        }

        if ($count === 0) {
            return ApiResponse::error('Butuh minimal 2 tim yang disetujui untuk membuat jadwal.', null, 422);
        }

        // Assign concrete date/time (and venue lane) to each fixture.
        $this->schedule->applySchedule($eventModel, $options);

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

        $base = $request->validate([
            'status' => ['required', Rule::in(['scheduled', 'ongoing', 'finished', 'cancelled'])],
            'scheduled_at' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
        ]);

        $finished = $base['status'] === 'finished';
        $setBased = MatchScoring::isSetBased($eventModel->sport_type);

        if ($setBased) {
            $data = $request->validate([
                'sets' => [$finished ? 'required' : 'nullable', 'array', 'max:7'],
                'sets.*.home' => ['required', 'integer', 'min:0', 'max:99'],
                'sets.*.away' => ['required', 'integer', 'min:0', 'max:99'],
            ]);

            $sets = $data['sets'] ?? null;
            $won = $sets ? MatchScoring::setsWon($sets) : ['home' => null, 'away' => null];

            if ($finished && empty($sets)) {
                return ApiResponse::error('Isi skor minimal satu set untuk menyelesaikan pertandingan.', [
                    'sets' => ['Skor set wajib diisi.'],
                ], 422);
            }

            $payload = [...$base, 'sets' => $sets, 'home_score' => $won['home'], 'away_score' => $won['away']];
        } else {
            $data = $request->validate([
                'home_score' => ['nullable', 'integer', 'min:0', 'max:999'],
                'away_score' => ['nullable', 'integer', 'min:0', 'max:999'],
            ]);

            if ($finished && ($data['home_score'] === null || $data['away_score'] === null)) {
                return ApiResponse::error('Skor kedua tim wajib diisi untuk menyelesaikan pertandingan.', [
                    'home_score' => ['Skor wajib diisi.'],
                ], 422);
            }

            $payload = [...$base, 'home_score' => $data['home_score'], 'away_score' => $data['away_score'], 'sets' => null];
        }

        $format = $eventModel->tournament_format;
        $isKnockout = in_array($format, ['knockout_single', 'knockout_double'], true);

        if ($finished && $isKnockout && $payload['home_score'] === $payload['away_score']) {
            return ApiResponse::error('Pertandingan knockout tidak boleh berakhir seri — tentukan pemenangnya.', null, 422);
        }

        // Entering or editing a result makes it provisional again; it only
        // counts (standings / bracket) once confirmed.
        $payload['confirmed_at'] = null;
        $matchModel->update($payload);

        return ApiResponse::success(
            new MatchResource($matchModel->load(['homeTeam', 'awayTeam'])),
            $finished ? 'Hasil disimpan — menunggu konfirmasi' : 'Pertandingan diperbarui',
        );
    }

    /**
     * Set when/where a fixture is played, without touching its result. Safe to
     * call on finished matches (kickoff time only; scores stay intact).
     */
    public function updateSchedule(Request $request, string $organization, string $match): JsonResponse
    {
        $matchModel = $this->match($request, $match);

        $data = $request->validate([
            'scheduled_at' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
        ]);

        $matchModel->update([
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'venue' => $data['venue'] ?? null,
        ]);

        return ApiResponse::success(
            new MatchResource($matchModel->load(['homeTeam', 'awayTeam'])),
            'Jadwal pertandingan diperbarui',
        );
    }

    /**
     * Confirm (finalize) or unconfirm a result. Confirming a knockout match
     * pushes the winner/loser onward.
     */
    public function confirmResult(Request $request, string $organization, string $match): JsonResponse
    {
        $matchModel = $this->match($request, $match);
        $eventModel = $matchModel->event;

        $validated = $request->validate(['confirmed' => ['required', 'boolean']]);

        if (! $validated['confirmed']) {
            $matchModel->update(['confirmed_at' => null]);

            return ApiResponse::success(
                new MatchResource($matchModel->load(['homeTeam', 'awayTeam'])),
                'Konfirmasi dibatalkan',
            );
        }

        if (! $matchModel->isFinished()) {
            return ApiResponse::error('Lengkapi skor pertandingan sebelum dikonfirmasi.', null, 422);
        }

        $matchModel->update(['confirmed_at' => now()]);

        $format = $eventModel->tournament_format;
        if ($format === 'knockout_single') {
            $this->schedule->advanceWinner($matchModel->fresh());
        } elseif ($format === 'knockout_double') {
            $this->schedule->advanceDouble($matchModel->fresh());
        }

        return ApiResponse::success(
            new MatchResource($matchModel->fresh()->load(['homeTeam', 'awayTeam'])),
            'Hasil dikonfirmasi',
        );
    }

    /**
     * @return Collection<int, GameMatch>
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
     * @param  Team|null  $team
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
