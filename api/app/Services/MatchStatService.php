<?php

namespace App\Services;

use App\Models\GameMatch;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Replacing the per-player stats of a match, and the one arithmetic rule that
 * write has to obey.
 *
 * Two doors write these rows now — the organizer's own editor
 * (MatchController::saveMatchStats) and the match staff's officiating surface —
 * and the same reasoning MatchResultService is built on applies here: if each
 * carried its own copy of the roster filter and the assist ceiling, one of them
 * would drift, and the one that drifted would be the one nobody is watching.
 *
 * The write is a full replace, deliberately: every row of the match is deleted
 * and rewritten from the payload. That is what lets an organizer correct 3
 * yellows down to 2 — and it is exactly why suspensions are derived rather than
 * stored (see DisciplineService), because a replace emits no event a status
 * column could listen for.
 */
class MatchStatService
{
    /**
     * Validation rules for a stats payload, keyed to the sport of this match.
     *
     * Lives here rather than in each controller because `stat_key` is checked
     * against the sport's own catalog: a caller that wrote its own `Rule::in`
     * would be free to accept a key this service then silently drops.
     *
     * @return array<string, mixed>
     */
    public function rules(GameMatch $match): array
    {
        return [
            'stats' => ['present', 'array'],
            'stats.*.player_id' => ['required', 'uuid'],
            'stats.*.stat_key' => ['required', 'string', Rule::in(Catalog::statKeys($match->event->sport_type))],
            'stats.*.value' => ['required', 'integer', 'min:0', 'max:999'],
        ];
    }

    /**
     * Everything the stat editor reads: the stat columns of the sport, both
     * rosters, and the current per-player tally.
     *
     * Paired with replace() on purpose — the editor posts back exactly the
     * shape it was given, so the two halves drifting apart would mean an
     * editor that can display a column it cannot save. The organizer's surface
     * and the staff's surface read through this same method for the same
     * reason they write through the same one.
     *
     * @return array<string, mixed>
     */
    public function snapshot(GameMatch $match): array
    {
        $match->loadMissing(['homeTeam.players', 'awayTeam.players', 'stats']);

        return [
            'columns' => Catalog::statColumns($match->event->sport_type),
            'home_team' => $this->teamRoster($match->homeTeam),
            'away_team' => $this->teamRoster($match->awayTeam),
            // player_id => { stat_key => value }
            'stats' => $match->stats
                ->groupBy('player_id')
                ->map(fn ($rows) => $rows->mapWithKeys(fn ($s) => [$s->stat_key => $s->value])),
        ];
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

    /**
     * Replace every stat row of a match with the given payload.
     *
     * @param  array<int, array{player_id: string, stat_key: string, value: int}>  $stats  already validated by rules()
     * @return string|null the assist error message, or null when the write went through
     */
    public function replace(GameMatch $match, array $stats): ?string
    {
        $match->loadMissing(['homeTeam.players', 'awayTeam.players']);

        $roster = $this->roster($match);

        // Checked before anything is deleted: a 422 that arrives after the
        // replace looks identical from the outside, but has already thrown the
        // old rows away.
        if ($error = $this->assistError($match, $stats, $roster)) {
            return $error;
        }

        $match->stats()->delete();

        foreach ($stats as $entry) {
            if ($entry['value'] < 1 || ! $roster->has($entry['player_id'])) {
                continue;
            }

            $match->stats()->create([
                'team_id' => $roster[$entry['player_id']]['team_id'],
                'player_id' => $entry['player_id'],
                'stat_key' => $entry['stat_key'],
                'value' => $entry['value'],
            ]);
        }

        return null;
    }

    /**
     * player_id => team_id, restricted to the two teams on the pitch.
     *
     * This is also the authorization on the payload: a player id belonging to
     * any other team is dropped rather than rejected, so a stale roster in an
     * open tab cannot write onto a squad that is not playing.
     *
     * @return Collection<string, array{player_id: string, team_id: string}>
     */
    protected function roster(GameMatch $match): Collection
    {
        return collect([$match->homeTeam, $match->awayTeam])
            ->filter()
            ->flatMap(fn ($team) => $team->players->map(fn ($p) => ['player_id' => $p->id, 'team_id' => $team->id]))
            ->keyBy('player_id');
    }

    /**
     * A goal carries at most one assist, so a side can never register more
     * assists than it scored. The scoreline is the source of truth when the
     * match is finished; otherwise the recorded scorers are.
     *
     * @param  array<int, array{player_id: string, stat_key: string, value: int}>  $stats
     * @param  Collection<string, array{player_id: string, team_id: string}>  $roster
     * @return string|null the error message, or null when the stats are sound
     */
    protected function assistError(GameMatch $match, array $stats, Collection $roster): ?string
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
}
