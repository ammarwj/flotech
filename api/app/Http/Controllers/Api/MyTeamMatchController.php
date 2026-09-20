<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MatchLineupResource;
use App\Http\Resources\MatchResource;
use App\Models\GameMatch;
use App\Models\MatchLineup;
use App\Models\MatchLineupPlayer;
use App\Models\Team;
use App\Services\LineupService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The manager's side of the team sheet: their fixtures, and the lineup they hand
 * in for each one.
 *
 * Deliberately a sibling of MyTeamController rather than a branch inside it, but
 * it shares the one thing that matters — `scope()`, which is
 * `auth()->user()->managedTeams()`. That relation is the only proof this team is
 * theirs, it is what every MyTeamController action already uses, and a team
 * belonging to somebody else is simply not in the query: a 404, not a 403, so the
 * row's existence stays unconfirmed.
 *
 * There is no organizer surface here and no event personnel one. A manager reaches
 * only fixtures their own team is playing in — `matchFor()` is where that is
 * enforced, and it is the reason a match id is never taken on trust.
 */
class MyTeamMatchController extends Controller
{
    public function __construct(protected LineupService $lineups) {}

    /**
     * Fixtures this team is in, home or away.
     */
    public function index(string $team): JsonResponse
    {
        $model = $this->team($team);

        $matches = GameMatch::where('category_id', $model->category_id)
            ->where(fn ($q) => $q->where('home_team_id', $model->id)->orWhere('away_team_id', $model->id))
            ->with(['homeTeam', 'awayTeam'])
            ->orderByRaw('scheduled_at is null, scheduled_at')
            ->orderBy('round')
            ->orderBy('order')
            ->get();

        // The sheet's status belongs on the fixture list: it is the whole reason
        // the manager opened the page, and fetching it per row afterwards would
        // be one request per match.
        $lineups = MatchLineup::whereIn('match_id', $matches->pluck('id'))
            ->where('team_id', $model->id)
            ->get()
            ->keyBy('match_id');

        return ApiResponse::success([
            'team' => $this->teamRef($model),
            'matches' => $matches->map(fn ($match) => [
                ...(new MatchResource($match))->toArray(request()),
                'lineup' => $lineups->get($match->id)
                    ? new MatchLineupResource($lineups->get($match->id))
                    : null,
            ])->values(),
        ]);
    }

    /**
     * This team's sheet for one fixture, with the roster to pick from.
     *
     * The roster travels with it on purpose: the editor cannot be drawn without
     * both, and two requests would let it render a sheet naming players it has no
     * names for.
     */
    public function lineup(string $team, string $match): JsonResponse
    {
        $model = $this->team($team);
        $matchModel = $this->matchFor($model, $match);

        $lineup = $this->lineups->findOrCreate($matchModel, $model);

        return ApiResponse::success($this->payload($model, $matchModel, $lineup));
    }

    /**
     * Replace the sheet. Full-list contract, same as the roster editor.
     */
    public function saveLineup(Request $request, string $team, string $match): JsonResponse
    {
        $model = $this->team($team);
        $matchModel = $this->matchFor($model, $match);

        $data = $request->validate([
            'players' => ['present', 'array'],
            // Load-bearing exactly as `players.*.id` is in RegisterTeamRequest: an
            // id means "this is the row you already have", and dropping the rule
            // turns every save into a delete-and-recreate that loses nothing
            // visible — until something starts pointing at these rows.
            'players.*.id' => ['nullable', 'string'],
            'players.*.player_id' => ['required', 'string'],
            'players.*.role' => ['required', Rule::in(MatchLineupPlayer::ROLES)],
            'officials' => ['present', 'array'],
            'officials.*.id' => ['nullable', 'string'],
            'officials.*.team_official_id' => ['required', 'string'],
        ]);

        $lineup = DB::transaction(
            fn () => $this->lineups->sync($matchModel, $model, $data['players'], $data['officials']),
        );

        return ApiResponse::success(
            $this->payload($model, $matchModel, $lineup),
            'Susunan pemain disimpan',
        );
    }

    /**
     * Hand it to the referee. From here it is locked until they answer.
     */
    public function submitLineup(string $team, string $match): JsonResponse
    {
        $model = $this->team($team);
        $matchModel = $this->matchFor($model, $match);

        $lineup = $this->lineups->submit(
            $this->lineups->findOrCreate($matchModel, $model),
            auth('api')->user(),
        );

        return ApiResponse::success(
            $this->payload($model, $matchModel, $lineup),
            'Susunan pemain dikirim ke wasit',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Team $team, GameMatch $match, MatchLineup $lineup): array
    {
        $lineup->load(['players.player', 'officials.official', 'reviewer']);
        $lineup->setRelation('team', $team);

        // Who this team may not field here, and the rulebook that names the
        // reason. Sent with the sheet rather than fetched separately so the
        // editor can grey those players out before the manager picks them —
        // proactive as well as reactive, the same shape the plan gates take.
        // `rules` is null for a sport without cards, which is the editor's
        // signal to render nothing at all.
        $discipline = $this->lineups->bansFor($match, $team);

        return [
            'team' => $this->teamRef($team),
            'match' => new MatchResource($match->load(['homeTeam', 'awayTeam'])),
            'lineup' => new MatchLineupResource($lineup),
            'bans' => $discipline['bans'],
            'discipline_rules' => $discipline['rules'],
            // The pool the sheet is drawn from. Sent whole rather than filtered to
            // what is not yet named: a manager moving a player between starters
            // and the bench is the common edit, and a list that shrinks as they
            // work is a list they cannot put anyone back into.
            'roster' => $team->players()->orderBy('jersey_number')->get()->map(fn ($p) => [
                'id' => $p->id,
                'full_name' => $p->full_name,
                'jersey_number' => $p->jersey_number,
                'position' => $p->position,
            ])->values(),
            'officials' => $team->officials()->get()->map(fn ($o) => [
                'id' => $o->id,
                'full_name' => $o->full_name,
                'role' => $o->role,
            ])->values(),
        ];
    }

    /**
     * The team, and the few things about its event the screens cannot draw
     * without: the sport (official role labels come from its catalogue) and the
     * timezone (a kickoff is a fact about the venue's clock, not the reader's).
     *
     * @return array<string, mixed>
     */
    protected function teamRef(Team $team): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'event_id' => $team->event_id,
            'category_id' => $team->category_id,
            'event_name' => $team->event?->name,
            'sport_type' => $team->event?->sport_type,
            'timezone' => $team->event?->timezone,
        ];
    }

    protected function team(string $team): Team
    {
        return $this->scope()->with('event')->findOrFail($team);
    }

    /**
     * A fixture this team is actually playing in.
     *
     * The `where` on both sides is the authorization, not a convenience: without
     * it a manager could name their own players on somebody else's fixture, and
     * the unique index would happily store it.
     */
    protected function matchFor(Team $team, string $match): GameMatch
    {
        return GameMatch::whereKey($match)
            ->where(fn ($q) => $q->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id))
            ->firstOrFail();
    }

    /**
     * Base query scoped to the authenticated participant's managed teams — the
     * same one MyTeamController uses, for the same reason.
     */
    protected function scope()
    {
        return auth('api')->user()->managedTeams();
    }
}
