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
use App\Support\BracketSeeding;
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
            $approved = $categoryModel->teams()->where('status', 'approved')->pluck('id');
            $half = intdiv(BracketSeeding::sizeFor($approved->count()), 2);

            $seeding = $request->validate(BracketSeeding::validationRules($half, $approved));

            if ($error = $this->seedingError($seeding)) {
                return $error;
            }

            $count = $this->schedule->generateKnockout(
                $categoryModel,
                BracketSeeding::normalize($seeding, $half),
            );
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

        // The pool is the qualifiers, not every approved team: this endpoint
        // builds the bracket out of who came through the groups, and its own
        // guards above already refuse to run until every group game is final.
        // Getting a non-qualifier into the bracket is a results problem, and
        // its fix is the slot editor, not seeding.
        $half = intdiv($config->bracketSize(), 2);
        $seeding = $request->validate(BracketSeeding::validationRules($half, $qualifiers));

        if ($error = $this->seedingError($seeding)) {
            return $error;
        }

        $pairs = BracketSeeding::normalize($seeding, $half);

        if ($this->schedule->generateHybridKnockout($categoryModel, $qualifiers, $pairs) === 0) {
            return ApiResponse::error('Butuh minimal 2 tim lolos untuk membuat bracket.', null, 422);
        }

        $this->schedule->applySchedule($categoryModel, [], 'knockout');

        return ApiResponse::success(
            MatchResource::collection($this->orderedMatches($categoryModel)),
            'Bracket knockout dibuat: '.count($qualifiers).' tim lolos'
                .($pairs !== null ? ' (seeding manual)' : ''),
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
     * schedule instead of auto-generating one.
     *
     * Without `group_name` it is a generic fixture (stage null): for a league it
     * flows into the standings once its result is confirmed, and it never drives
     * a knockout bracket. Naming a group instead files it under the group stage,
     * where it counts toward that group's table — which is why both teams have
     * to already belong to that group.
     */
    public function storeManual(Request $request, string $organization, string $event, string $category): JsonResponse
    {
        $categoryModel = $this->category($request, $event, $category);

        // Only approved teams of this category may be paired.
        $approved = $categoryModel->teams()->where('status', 'approved')->pluck('id');

        if ($approved->count() < 2) {
            return ApiResponse::error('Butuh minimal 2 tim yang disetujui untuk menambah pertandingan.', null, 422);
        }

        $groups = $categoryModel->engine() === 'hybrid'
            ? HybridConfig::fromCategory($categoryModel)->groupNames()
            : [];

        $data = $request->validate([
            'home_team_id' => ['required', 'uuid', Rule::in($approved)],
            'away_team_id' => ['required', 'uuid', 'different:home_team_id', Rule::in($approved)],
            // Naming a group promotes the fixture into the group stage, where it
            // counts toward that group's table. Only hybrid has groups at all.
            'group_name' => ['nullable', 'string', Rule::in($groups)],
            'scheduled_at' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
        ]);

        $group = $data['group_name'] ?? null;

        if ($group !== null) {
            // A group fixture between teams of different groups would put a
            // result into a table neither of them plays in. The draw decides
            // who is in which group; this only records a match inside one.
            $outsiders = $categoryModel->teams()
                ->whereIn('id', [$data['home_team_id'], $data['away_team_id']])
                ->where(fn ($q) => $q->whereNull('group_name')->orWhere('group_name', '!=', $group))
                ->pluck('name');

            if ($outsiders->isNotEmpty()) {
                return ApiResponse::error(
                    "Tim ini bukan peserta Grup {$group}: ".$outsiders->join(', ').'. Ubah lewat Undian Grup dulu.',
                    ['group_name' => ['Kedua tim harus berada di grup yang sama.']],
                    422,
                );
            }
        }

        // A grouped fixture joins the last matchday rather than starting a new
        // one; adding three would otherwise sprout three headings. Round is
        // presentation only — standings never read it.
        $round = $group === null
            ? 1
            : (int) ($categoryModel->matches()->where('stage', 'group')->max('round') ?: 1);

        $siblings = $categoryModel->matches();

        if ($group === null) {
            $siblings->whereNull('stage');
        } else {
            $siblings->where('stage', 'group')->where('round', $round);
        }

        $match = GameMatch::create([
            'event_id' => $categoryModel->event_id,
            'category_id' => $categoryModel->id,
            'stage' => $group === null ? null : 'group',
            'group_name' => $group,
            'round' => $round,
            'leg' => 1,
            // Keep manual fixtures in insertion order within their bucket.
            'order' => (int) $siblings->max('order') + 1,
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

        // Whoever runs the organization signs off by the act of saving; an
        // operator only records, and their result waits for someone who does.
        // That is the whole point of the two-step — it costs a click exactly
        // when there really are two people.
        $signsOff = $this->org($request)->isAdministeredBy($request->user());
        $autoConfirm = $signsOff
            && $payload['status'] === 'finished'
            && $payload['home_score'] !== null
            && $payload['away_score'] !== null;

        // Whatever this result had already pushed into the next round has to
        // come back out before the new one goes in — otherwise editing a
        // confirmed knockout result leaves the previous winner stranded there.
        if ($matchModel->isConfirmed()) {
            $this->withdrawFrom($matchModel);
        }

        $payload['confirmed_at'] = $autoConfirm ? now() : null;
        $matchModel->update($payload);

        if ($autoConfirm) {
            $this->propagateFrom($matchModel->fresh());
        }

        return ApiResponse::success(
            new MatchResource($matchModel->fresh()->load(['homeTeam', 'awayTeam'])),
            $finished
                ? ($autoConfirm ? 'Hasil disimpan & dikonfirmasi' : 'Hasil disimpan — menunggu konfirmasi admin')
                : 'Pertandingan diperbarui',
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
     * Replace the teams in a first-round bracket slot, for a seed that came out
     * wrong. Everything the old occupant reached downstream is undone first.
     *
     * Deliberately not part of updateResult(): every guard in there is written
     * assuming the teams are fixed, so a swap posted alongside a scoreline
     * would land in a state none of them anticipate.
     */
    public function updateTeams(Request $request, string $organization, string $match): JsonResponse
    {
        $matchModel = $this->match($request, $match);
        $category = $matchModel->category;
        $engine = $category->engine();

        if ($engine === 'knockout_double') {
            return ApiResponse::error(
                'Bracket double elimination belum bisa diedit manual.',
                ['feature' => 'bracket_edit'],
                422,
            );
        }

        // Hybrid keeps its bracket under stage 'knockout'; single elimination
        // has no stages at all. Anything else is a group or league fixture.
        $isBracket = $engine === 'knockout_single'
            ? $matchModel->stage === null
            : $matchModel->stage === 'knockout';

        if (! $isBracket) {
            return ApiResponse::error(
                'Hanya slot bracket knockout yang bisa diubah timnya.',
                ['feature' => 'bracket_edit'],
                422,
            );
        }

        if ($matchModel->round !== 1) {
            return ApiResponse::error(
                'Tim di babak lanjutan ditentukan oleh hasil babak sebelumnya — ubah pasangan di babak pertama.',
                ['feature' => 'bracket_edit'],
                422,
            );
        }

        // A single-elimination bracket shares (stage null, round 1) with the
        // hand-added friendlies of storeManual(), whose order keeps counting
        // past the bracket. Those are not slots — delete and re-add instead.
        if ($matchModel->stage === null && $matchModel->order >= $this->bracketSlotCount($matchModel)) {
            return ApiResponse::error(
                'Pertandingan tambahan bukan bagian dari bracket — hapus lalu tambahkan ulang.',
                ['feature' => 'bracket_edit'],
                422,
            );
        }

        // Keyed on the scoreline, not on status: a bye is written finished and
        // confirmed with no scores, and "the bye went to the wrong team" is one
        // of the seeding mistakes this endpoint exists to fix.
        if ($matchModel->home_score !== null || $matchModel->away_score !== null || $matchModel->sets !== null) {
            return ApiResponse::error(
                'Hasil pertandingan ini sudah diisi — hapus hasilnya dulu sebelum mengganti tim.',
                null,
                422,
            );
        }

        $pool = $engine === 'knockout_single'
            ? $category->teams()->where('status', 'approved')->pluck('id')->all()
            : $this->standings->qualifiers($category);

        $data = $request->validate([
            'home_team_id' => ['nullable', 'uuid', Rule::in($pool)],
            'away_team_id' => ['nullable', 'uuid', 'different:home_team_id', Rule::in($pool)],
        ]);

        $home = $data['home_team_id'] ?? null;
        $away = $data['away_team_id'] ?? null;

        if ($home === null && $away === null) {
            return ApiResponse::error('Slot harus punya minimal satu tim.', [
                'home_team_id' => ['Pilih tim untuk slot ini.'],
            ], 422);
        }

        if ($home === null) {
            return ApiResponse::error('Isi tim di slot pertama; slot kedua yang dikosongkan berarti bye.', [
                'home_team_id' => ['Pilih tim untuk slot pertama.'],
            ], 422);
        }

        // Picking a team that already has a slot swaps the two, rather than
        // being refused. In a full bracket every eligible team is placed
        // somewhere, so a plain replacement would have nothing to offer — what
        // the organizer actually wants is to move two seeds past each other,
        // and doing it here keeps the results that a regenerate would burn.
        $vacated = $this->displaceOccupants($matchModel, $home, $away);

        if ($vacated instanceof JsonResponse) {
            return $vacated;
        }

        // Clear first, then re-advance: the other way round would empty the very
        // slot a walkover just filled.
        $cleared = $this->schedule->clearDownstream($matchModel);

        foreach ($vacated as $donor) {
            $cleared += $this->schedule->clearDownstream($donor);
        }

        $this->reseat($matchModel, $home, $away);

        foreach ($vacated as $donor) {
            $this->reseat($donor, $donor->home_team_id, $donor->away_team_id);
        }

        return ApiResponse::success(
            new MatchResource($matchModel->fresh()->load(['homeTeam', 'awayTeam'])),
            $cleared > 0
                ? "Tim diperbarui — {$cleared} pertandingan babak berikutnya direset"
                : 'Tim di slot bracket diperbarui',
        );
    }

    /**
     * Hand the outgoing teams of $match to whichever first-round slots the
     * incoming ones are being taken from.
     *
     * The donors come back with their new occupants set but *unsaved*, so the
     * caller can clear their descendants before the change lands.
     *
     * @return \Illuminate\Support\Collection<int, GameMatch>|JsonResponse the donors, or the error to return
     */
    protected function displaceOccupants(GameMatch $match, ?string $home, ?string $away)
    {
        $outgoing = [$match->home_team_id, $match->away_team_id];
        $donors = collect();

        foreach ([$home, $away] as $side => $incoming) {
            // Already in this tie: no other slot is involved.
            if ($incoming === null || in_array($incoming, $outgoing, true)) {
                continue;
            }

            $donor = $donors->first(fn ($m) => in_array($incoming, [$m->home_team_id, $m->away_team_id], true))
                ?? $this->bracketSlots($match)->first(
                    fn ($m) => in_array($incoming, [$m->home_team_id, $m->away_team_id], true)
                );

            if (! $donor) {
                continue; // the team is unplaced — a straight replacement
            }

            if ($donor->home_score !== null || $donor->away_score !== null || $donor->sets !== null) {
                return ApiResponse::error(
                    'Tim itu sudah bermain di slot lain — tukar hanya bisa sebelum salah satunya dimainkan.',
                    [$side === 0 ? 'home_team_id' : 'away_team_id' => ['Slot asal tim ini sudah punya hasil.']],
                    422,
                );
            }

            // The team leaving this slot takes the incoming one's place.
            $replacement = $outgoing[$side] ?? null;
            if ($donor->home_team_id === $incoming) {
                $donor->home_team_id = $replacement;
            } else {
                $donor->away_team_id = $replacement;
            }

            // A slot may never hold an away team with nobody at home; that is
            // the shape the request validation refuses, so don't create it.
            if ($donor->home_team_id === null) {
                $donor->home_team_id = $donor->away_team_id;
                $donor->away_team_id = null;
            }

            $donors = $donors->reject(fn ($m) => $m->is($donor))->push($donor);
        }

        return $donors;
    }

    /**
     * Seat a pair in a bracket slot, dropping any result it held. A lone team
     * is a walkover, written exactly as generation writes one, and goes
     * straight through to the next round.
     */
    protected function reseat(GameMatch $match, ?string $home, ?string $away): void
    {
        $bye = $home !== null && $away === null;

        $match->update([
            'home_team_id' => $home,
            'away_team_id' => $away,
            'home_score' => null,
            'away_score' => null,
            'home_penalty' => null,
            'away_penalty' => null,
            'sets' => null,
            'status' => $bye ? 'finished' : 'scheduled',
            'confirmed_at' => $bye ? now() : null,
        ]);

        if ($bye) {
            $this->schedule->advanceWinner($match->fresh());
        }
    }

    /**
     * The other first-round slots of this match's bracket.
     *
     * A single-elimination bracket shares `stage null, round 1` with the manual
     * extra fixtures of storeManual(), whose order keeps counting past the
     * bracket — without the cut-off a friendly would be treated as a slot and
     * get its teams shuffled.
     *
     * @return Collection<int, GameMatch>
     */
    protected function bracketSlots(GameMatch $match)
    {
        $query = $match->category->matches()
            ->where('round', 1)
            ->whereKeyNot($match->id);

        if ($match->stage === null) {
            $query->whereNull('stage')->where('order', '<', $this->bracketSlotCount($match));
        } else {
            $query->where('stage', $match->stage);
        }

        return $query->orderBy('order')->get();
    }

    /**
     * First-round slots in the bracket this match belongs to.
     *
     * Derived from how tall the bracket is rather than from the team count, so
     * it stays right when teams are approved or withdrawn after generation.
     */
    protected function bracketSlotCount(GameMatch $match): int
    {
        $query = $match->category->matches();
        $maxRound = (int) ($match->stage === null
            ? $query->whereNull('stage')->max('round')
            : $query->where('stage', $match->stage)->max('round'));

        return intdiv(2 ** max(1, $maxRound), 2);
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
        $this->propagateFrom($matchModel->fresh());

        return ApiResponse::success(
            new MatchResource($matchModel->fresh()->load(['homeTeam', 'awayTeam'])),
            'Hasil dikonfirmasi',
        );
    }

    /**
     * Send a settled result onward: the winner into the next bracket slot.
     *
     * Every path that makes a result final goes through here, so confirming by
     * hand and confirming by saving as an admin can't propagate differently.
     */
    protected function propagateFrom(GameMatch $match): void
    {
        $engine = $match->category->engine();

        if ($engine === 'knockout_single' || $match->stage === 'knockout') {
            $this->schedule->advanceWinner($match);
            // A semifinal also sends its loser sideways, when the category
            // plays for third place.
            $this->schedule->advanceLoser($match);
        } elseif ($engine === 'knockout_double') {
            $this->schedule->advanceDouble($match);
        }
    }

    /**
     * The inverse: pull back whatever this result had already sent onward.
     *
     * @return int matches reset
     */
    protected function withdrawFrom(GameMatch $match): int
    {
        $engine = $match->category->engine();

        if ($engine === 'knockout_single' || $engine === 'knockout_double' || $match->stage === 'knockout') {
            return $this->schedule->clearDownstream($match);
        }

        return 0;
    }

    /**
     * Move a fixture between scheduled / ongoing / cancelled.
     *
     * `finished` is deliberately absent from the accepted values rather than
     * merely guarded: updateResult() stays the only way to finish a match, and
     * it still validates the scoreline. There is no code path where the two
     * doors can disagree, which a transition table would not have given us.
     *
     * This endpoint **never writes scores, sets or penalties** — that is why it
     * exists. updateResult() rebuilds its payload from scratch and nulls
     * whatever the request left out, so using it for a status-only change would
     * silently wipe a running scoreline.
     *
     * Invariant it upholds: `confirmed_at !== null` implies `status ===
     * 'finished'`. StandingService filters on exactly that pair, so a stale
     * confirmation on a cancelled match would be invisible today and a bug the
     * moment either filter is relaxed.
     */
    public function updateStatus(Request $request, string $organization, string $match): JsonResponse
    {
        $matchModel = $this->match($request, $match);

        $validated = $request->validate(
            ['status' => ['required', Rule::in(['scheduled', 'ongoing', 'cancelled'])]],
            ['status.in' => 'Pertandingan diselesaikan dengan menyimpan skor, bukan dari sini.'],
        );

        // Nothing to do — and saying so early keeps a double-click from walking
        // the bracket a second time and clearing a slot that was already reset.
        if ($matchModel->status === $validated['status']) {
            return ApiResponse::success(
                new MatchResource($matchModel->load(['homeTeam', 'awayTeam'])),
                'Status pertandingan tidak berubah',
            );
        }

        $cleared = 0;

        // A confirmed result has already pushed its winner into the next round.
        // Every status reachable here means "that result is no longer final", so
        // the winner has to come back out — the same edges confirmResult() walks
        // forward, walked backward.
        if ($matchModel->isConfirmed()) {
            $matchModel->confirmed_at = null;
            $cleared = $this->withdrawFrom($matchModel);
        }

        $matchModel->status = $validated['status'];
        $matchModel->save();

        $message = match ($validated['status']) {
            'ongoing' => 'Pertandingan dimulai',
            'cancelled' => 'Pertandingan dibatalkan',
            default => 'Pertandingan dikembalikan ke terjadwal',
        };

        return ApiResponse::success(
            new MatchResource($matchModel->fresh()->load(['homeTeam', 'awayTeam'])),
            $cleared > 0
                ? "{$message} — {$cleared} pertandingan babak berikutnya direset"
                : $message,
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
     * The checks on a manual seeding payload that validation rules can't state:
     * `Rule::in` can't see across slots, and `distinct` can't reach two sibling
     * keys of one row.
     *
     * @param  array<string, mixed>  $seeding  the validated payload
     * @return JsonResponse|null the error to return, or null when it's sound
     */
    protected function seedingError(array $seeding): ?JsonResponse
    {
        if (! BracketSeeding::isManual($seeding)) {
            return null;
        }

        $pairs = $seeding['pairs'] ?? [];

        if ($pairs === []) {
            return ApiResponse::error('Isi minimal satu pasangan untuk seeding manual.', [
                'pairs' => ['Pasangan babak pertama wajib diisi.'],
            ], 422);
        }

        $orders = array_column($pairs, 'order');
        if (count($orders) !== count(array_unique($orders))) {
            return ApiResponse::error('Setiap slot bracket hanya boleh diisi sekali.', [
                'pairs' => ['Ada slot yang dikirim lebih dari sekali.'],
            ], 422);
        }

        if (BracketSeeding::duplicateTeam($pairs) !== null) {
            return ApiResponse::error('Satu tim tidak boleh mengisi dua slot bracket.', [
                'pairs' => ['Ada tim yang dipilih lebih dari sekali.'],
            ], 422);
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
