<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MatchResource;
use App\Http\Resources\TeamResource;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Team;
use App\Services\Catalog;
use App\Services\GroupDrawService;
use App\Services\PlayerStatService;
use App\Services\ScheduleService;
use App\Services\StandingService;
use App\Support\ApiResponse;
use App\Support\HybridConfig;
use App\Support\MatchScoring;
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
        protected GroupDrawService $draw,
    ) {}

    /**
     * (Re)generate the round-robin schedule for the category's approved teams.
     */
    public function generate(Request $request, string $organization, string $event, string $category): JsonResponse
    {
        $categoryModel = $this->category($request, $event, $category);
        // A format is a preset; the engine is what actually schedules.
        $engine = $categoryModel->engine();

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

        if ($engine === 'hybrid') {
            // Nobody drawn yet → draw first, so "Generate" alone is enough to get going.
            if (! $categoryModel->teams()->where('status', 'approved')->whereNotNull('group_name')->exists()) {
                $this->draw->draw($categoryModel, HybridConfig::fromCategory($categoryModel)->drawMethod);
            }

            $count = $this->schedule->generateGroupStage($categoryModel);
        } elseif ($engine === 'league') {
            $count = $this->schedule->generateRoundRobin($categoryModel);
        } elseif ($engine === 'knockout_single') {
            $count = $this->schedule->generateKnockout($categoryModel);
        } elseif ($engine === 'knockout_double') {
            $count = $this->schedule->generateDoubleElim($categoryModel);
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
        $this->schedule->applySchedule($categoryModel, $options);

        return ApiResponse::success(
            MatchResource::collection($this->orderedMatches($categoryModel)),
            "Jadwal dibuat: {$count} pertandingan",
            201,
        );
    }

    /**
     * Draw the approved teams of a hybrid category into groups (random, seeding
     * pots, or the organizer's own assignment).
     */
    public function drawGroups(Request $request, string $organization, string $event, string $category): JsonResponse
    {
        $categoryModel = $this->category($request, $event, $category);

        if ($categoryModel->engine() !== 'hybrid') {
            return ApiResponse::error('Undian grup hanya untuk format Grup + Knockout.', null, 422);
        }

        $validated = $request->validate([
            'method' => ['required', Rule::in(Catalog::keys('draw_method'))],
            'assignments' => ['nullable', 'array'],       // team_id => group name
            'assignments.*' => ['string', 'max:10'],
            'pots' => ['nullable', 'array'],              // team_id => pot number
            'pots.*' => ['integer', 'min:1', 'max:16'],
        ]);

        $teams = $this->draw->draw(
            $categoryModel,
            $validated['method'],
            $validated['assignments'] ?? [],
            $validated['pots'] ?? [],
        );

        if ($teams->isEmpty()) {
            return ApiResponse::error('Belum ada tim yang disetujui untuk diundi.', null, 422);
        }

        return ApiResponse::success(TeamResource::collection($teams), 'Undian grup selesai');
    }

    /**
     * The knockout bracket as *planned*: which slot meets which ("Juara Grup A"
     * v "Runner-up Grup D"), with whoever currently holds each slot. Available
     * from the moment the groups are drawn, long before they finish.
     */
    public function knockoutPlan(Request $request, string $organization, string $event, string $category): JsonResponse
    {
        $categoryModel = $this->category($request, $event, $category);

        if ($categoryModel->engine() !== 'hybrid') {
            return ApiResponse::error('Rencana bracket hanya untuk format Grup + Knockout.', null, 422);
        }

        $config = HybridConfig::fromCategory($categoryModel);
        $slots = $this->standings->qualifierSlots($categoryModel);

        $pairs = $this->schedule->firstRoundPairs(
            $config->bracketSize(),
            $slots,
            fn (array $slot) => $slot['group'],
        );

        $pending = $categoryModel->matches()
            ->where('stage', 'group')
            ->where(fn ($q) => $q->where('status', '!=', 'finished')->orWhereNull('confirmed_at'))
            ->count();

        return ApiResponse::success([
            'bracket_size' => $config->bracketSize(),
            'qualifiers' => count($slots),
            'byes' => max(0, $config->bracketSize() - count($slots)),
            'group_matches_pending' => $pending,
            'ties' => array_map(fn ($pair, $order) => [
                'order' => $order,
                'home' => $pair[0],
                'away' => $pair[1],
            ], $pairs, array_keys($pairs)),
        ]);
    }

    /**
     * Build the knockout bracket of a hybrid event from the teams that came
     * through the groups. Group fixtures and results are left untouched.
     */
    public function generateKnockout(Request $request, string $organization, string $event, string $category): JsonResponse
    {
        $categoryModel = $this->category($request, $event, $category);

        if ($categoryModel->engine() !== 'hybrid') {
            return ApiResponse::error('Bracket knockout otomatis hanya untuk format Grup + Knockout.', null, 422);
        }

        $config = HybridConfig::fromCategory($categoryModel);

        // A group stage with no fixtures at all has nothing pending, which the
        // check below would read as "already played". Seeds would then come
        // straight from the alphabetical order the zeroed table falls back to.
        if ($categoryModel->matches()->where('stage', 'group')->count() === 0) {
            return ApiResponse::error(
                'Fase grup belum punya jadwal — buat jadwal grup dulu sebelum membuat bracket.',
                ['feature' => 'group_stage_incomplete'],
                422,
            );
        }

        $pending = $categoryModel->matches()
            ->where('stage', 'group')
            ->where(fn ($q) => $q->where('status', '!=', 'finished')->orWhereNull('confirmed_at'))
            ->count();

        if ($pending > 0) {
            return ApiResponse::error(
                "Masih ada {$pending} pertandingan grup yang belum selesai/dikonfirmasi.",
                ['feature' => 'group_stage_incomplete'],
                422,
            );
        }

        $qualifiers = $this->standings->qualifiers($categoryModel);

        if (count($qualifiers) > $config->bracketSize()) {
            return ApiResponse::error(
                'Babak awal knockout terlalu kecil untuk '.count($qualifiers).' tim yang lolos.',
                ['feature' => 'knockout_start'],
                422,
            );
        }

        if ($this->schedule->generateHybridKnockout($categoryModel, $qualifiers) === 0) {
            return ApiResponse::error('Butuh minimal 2 tim lolos untuk membuat bracket.', null, 422);
        }

        $this->schedule->applySchedule($categoryModel, [], 'knockout');

        return ApiResponse::success(
            MatchResource::collection($this->orderedMatches($categoryModel)),
            'Bracket knockout dibuat: '.count($qualifiers).' tim lolos',
            201,
        );
    }

    /**
     * Drop the knockout bracket of a hybrid category, back to the planned one.
     * Group fixtures and their results are left untouched — this is the undo for
     * a bracket that was generated too early, not a schedule reset.
     */
    public function destroyKnockout(Request $request, string $organization, string $event, string $category): JsonResponse
    {
        $categoryModel = $this->category($request, $event, $category);

        if ($categoryModel->engine() !== 'hybrid') {
            return ApiResponse::error('Hapus bracket hanya untuk format Grup + Knockout.', null, 422);
        }

        $deleted = $categoryModel->matches()->where('stage', 'knockout')->delete();

        if ($deleted === 0) {
            return ApiResponse::error('Belum ada bracket knockout untuk dihapus.', null, 422);
        }

        return ApiResponse::success(
            MatchResource::collection($this->orderedMatches($categoryModel)),
            "Bracket knockout dihapus: {$deleted} pertandingan",
        );
    }

    /**
     * List all matches for a category (organizer view).
     */
    public function index(Request $request, string $organization, string $event, string $category): JsonResponse
    {
        $categoryModel = $this->category($request, $event, $category);

        return ApiResponse::success(MatchResource::collection($this->orderedMatches($categoryModel)));
    }

    /**
     * Add a single fixture by hand, for organizers who already have their own
     * schedule instead of auto-generating one. Created as a generic fixture
     * (stage null): for a league it flows into the standings once its result is
     * confirmed; it never drives a knockout bracket.
     */
    public function storeManual(Request $request, string $organization, string $event, string $category): JsonResponse
    {
        $categoryModel = $this->category($request, $event, $category);

        // Only approved teams of this category may be paired.
        $approved = $categoryModel->teams()->where('status', 'approved')->pluck('id');

        if ($approved->count() < 2) {
            return ApiResponse::error('Butuh minimal 2 tim yang disetujui untuk menambah pertandingan.', null, 422);
        }

        $data = $request->validate([
            'home_team_id' => ['required', 'uuid', Rule::in($approved)],
            'away_team_id' => ['required', 'uuid', 'different:home_team_id', Rule::in($approved)],
            'scheduled_at' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
        ]);

        $match = GameMatch::create([
            'event_id' => $categoryModel->event_id,
            'category_id' => $categoryModel->id,
            'stage' => null,
            'round' => 1,
            'leg' => 1,
            // Keep manual fixtures in insertion order within the null stage.
            'order' => (int) $categoryModel->matches()->whereNull('stage')->max('order') + 1,
            'home_team_id' => $data['home_team_id'],
            'away_team_id' => $data['away_team_id'],
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'venue' => $data['venue'] ?? null,
            'status' => 'scheduled',
        ]);

        return ApiResponse::success(
            new MatchResource($match->load(['homeTeam', 'awayTeam'])),
            'Pertandingan ditambahkan',
            201,
        );
    }

    /**
     * Current league standings for a category.
     */
    public function standings(Request $request, string $organization, string $event, string $category): JsonResponse
    {
        $categoryModel = $this->category($request, $event, $category);

        return ApiResponse::success($this->standings->compute($categoryModel));
    }

    /**
     * Sport-aware player leaderboard for a category.
     */
    public function leaderboard(Request $request, string $organization, string $event, string $category): JsonResponse
    {
        $categoryModel = $this->category($request, $event, $category);

        return ApiResponse::success($this->stats->leaderboard($categoryModel));
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
            'columns' => Catalog::statColumns($matchModel->event->sport_type),
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
        $allowedKeys = Catalog::statKeys($matchModel->event->sport_type);

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

        if ($error = $this->assistError($matchModel, $validated['stats'], $roster)) {
            return ApiResponse::error($error, ['assists' => [$error]], 422);
        }

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
        $setBased = $eventModel->isSetBased();

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

        $shootout = $request->validate([
            'home_penalty' => ['nullable', 'integer', 'min:0', 'max:99'],
            'away_penalty' => ['nullable', 'integer', 'min:0', 'max:99'],
        ]);

        $level = $payload['home_score'] !== null && $payload['home_score'] === $payload['away_score'];
        $knockout = $this->isKnockoutTie($matchModel);

        // A knockout tie that ends level is settled on penalties; anything else
        // has no shootout at all.
        if ($finished && $knockout && $level) {
            $home = $shootout['home_penalty'] ?? null;
            $away = $shootout['away_penalty'] ?? null;

            if ($home === null || $away === null) {
                return ApiResponse::error('Skor imbang di knockout — isi hasil adu penalti.', [
                    'home_penalty' => ['Skor adu penalti wajib diisi.'],
                ], 422);
            }

            if ($home === $away) {
                return ApiResponse::error('Adu penalti tidak boleh berakhir imbang — tentukan pemenangnya.', [
                    'home_penalty' => ['Harus ada pemenang.'],
                ], 422);
            }

            $payload['home_penalty'] = $home;
            $payload['away_penalty'] = $away;
        } else {
            // Decided in normal time (or a group game): drop any stale shootout.
            $payload['home_penalty'] = null;
            $payload['away_penalty'] = null;
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

        $engine = $matchModel->category->engine();
        if ($engine === 'knockout_single' || $matchModel->stage === 'knockout') {
            $this->schedule->advanceWinner($matchModel->fresh());
        } elseif ($engine === 'knockout_double') {
            $this->schedule->advanceDouble($matchModel->fresh());
        }

        return ApiResponse::success(
            new MatchResource($matchModel->fresh()->load(['homeTeam', 'awayTeam'])),
            'Hasil dikonfirmasi',
        );
    }

    /**
     * Delete a fixture (manual or generated). Player stats and goals cascade
     * with the match at the database level.
     */
    public function destroy(Request $request, string $organization, string $match): JsonResponse
    {
        $this->match($request, $match)->delete();

        return ApiResponse::success(null, 'Pertandingan dihapus');
    }

    /**
     * A goal carries at most one assist, so a side can never register more
     * assists than it scored. The scoreline is the source of truth when the
     * match is finished; otherwise the recorded scorers are.
     *
     * @param  array<int, array{player_id: string, stat_key: string, value: int}>  $stats
     * @param  \Illuminate\Support\Collection<string, array{player_id: string, team_id: string}>  $roster
     * @return string|null the error message, or null when the stats are sound
     */
    protected function assistError(GameMatch $match, array $stats, $roster): ?string
    {
        $sport = $match->event->sport_type;
        $goalKey = Catalog::statKeyForRole($sport, 'goal');
        $assistKey = Catalog::statKeyForRole($sport, 'assist');

        if ($goalKey === null || $assistKey === null) {
            return null; // sport doesn't track both
        }

        // team_id => [goals, assists]
        $totals = [];
        foreach ($stats as $entry) {
            if (! $roster->has($entry['player_id'])) {
                continue;
            }

            $teamId = $roster[$entry['player_id']]['team_id'];
            $totals[$teamId][$entry['stat_key']] = ($totals[$teamId][$entry['stat_key']] ?? 0) + $entry['value'];
        }

        $scores = [
            $match->home_team_id => $match->home_score,
            $match->away_team_id => $match->away_score,
        ];

        foreach ($totals as $teamId => $tally) {
            $assists = $tally[$assistKey] ?? 0;
            $goals = $match->isFinished() ? (int) ($scores[$teamId] ?? 0) : ($tally[$goalKey] ?? 0);

            if ($assists > $goals) {
                return "Assist ({$assists}) tidak boleh lebih banyak dari gol tim ({$goals}) — satu gol maksimal satu assist.";
            }
        }

        return null;
    }

    /**
     * A match that must produce a winner: any tie in a knockout format, or the
     * knockout stage of a hybrid event. Group fixtures may end level.
     */
    protected function isKnockoutTie(GameMatch $match): bool
    {
        if ($match->stage === 'group') {
            return false;
        }

        return in_array($match->category->engine(), ['knockout_single', 'knockout_double'], true)
            || $match->stage === 'knockout';
    }

    /**
     * @return Collection<int, GameMatch>
     */
    protected function orderedMatches(EventCategory $category)
    {
        return $category->matches()
            ->with(['homeTeam', 'awayTeam'])
            ->orderByRaw("coalesce(stage, '') asc")
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
     * Resolve a category scoped to an event owned by the current organization.
     * The parent event is attached so the category's sport/date accessors don't
     * re-query.
     */
    protected function category(Request $request, string $eventId, string $categoryId): EventCategory
    {
        $event = $this->event($request, $eventId);
        $category = $event->categories()->findOrFail($categoryId);
        $category->setRelation('event', $event);

        return $category;
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
