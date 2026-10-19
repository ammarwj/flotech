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
 *
 * The bench is written here too, into `official_match_stats`, and the asymmetry
 * between the two halves is the rule rather than an omission: **an official may
 * only be given a card.** A coach does not score, so the official payload is
 * checked against the sport's `yellow`/`red` roles alone — not the whole
 * catalogue — and nothing about the bench reaches assistError() or the
 * goals-vs-score cross-check. Offering the full column set to the bench would put
 * a coach's gol into a tally the leaderboard then has to be taught to ignore.
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
        $sport = $match->event->sport_type;

        return [
            'stats' => ['present', 'array'],
            'stats.*.player_id' => ['required', 'uuid'],
            'stats.*.stat_key' => ['required', 'string', Rule::in(Catalog::statKeys($sport))],
            'stats.*.value' => ['required', 'integer', 'min:0', 'max:999'],
            // Absent means "leave the bench alone" rather than "clear it":
            // `sometimes` is what lets a client written before the bench existed
            // keep saving player stats without wiping a card somebody else
            // entered. An empty array *is* "clear it", the same reading
            // `officials` has on a registration update.
            'official_stats' => ['sometimes', 'array'],
            'official_stats.*.team_official_id' => ['required', 'uuid'],
            // The card keys only. Validated here rather than dropped in
            // replace() so an editor offering a gol column for the bench is told
            // off instead of quietly saving nothing.
            'official_stats.*.stat_key' => ['required', 'string', Rule::in(self::cardKeys($sport))],
            'official_stats.*.value' => ['required', 'integer', 'min:0', 'max:999'],
        ];
    }

    /**
     * The sport's card keys — the only stats the bench may ever carry.
     *
     * Read off the `yellow`/`red` roles, never a `stat_key` literal: an admin
     * renames keys from /admin/sports, and a sport whose card is called
     * `kartu_kuning` would silently lose the feature. Same rule DisciplineRules
     * runs on, for the same reason.
     *
     * @return array<int, string>
     */
    public static function cardKeys(?string $sport): array
    {
        return array_values(array_filter([
            Catalog::statKeyForRole($sport, 'yellow'),
            Catalog::statKeyForRole($sport, 'red'),
        ]));
    }

    /**
     * Everything the stat editor reads: the stat columns of the sport, both
     * rosters and benches, and the current per-person tally.
     *
     * Paired with replace() on purpose — the editor posts back exactly the
     * shape it was given, so the two halves drifting apart would mean an
     * editor that can display a column it cannot save. The organizer's surface
     * and the staff's surface read through this same method for the same
     * reason they write through the same one.
     *
     * `card_columns` is sent *beside* `columns` rather than letting the client
     * filter the one it already has. The filter is the rule that decides what
     * the bench may be given, it lives on the server, and a second copy in the
     * editor is a second answer to it — the shape `effective_payment_method`
     * exists to avoid. An empty list is the signal to render no bench block at
     * all, which is what a sport without cards gets.
     *
     * @return array<string, mixed>
     */
    public function snapshot(GameMatch $match): array
    {
        $match->loadMissing([
            'homeTeam.players', 'homeTeam.officials',
            'awayTeam.players', 'awayTeam.officials',
            'stats', 'officialStats',
        ]);

        $sport = $match->event->sport_type;
        $cardKeys = self::cardKeys($sport);

        return [
            'columns' => Catalog::statColumns($sport),
            'card_columns' => array_values(array_filter(
                Catalog::statColumns($sport),
                fn (array $column) => in_array($column['key'], $cardKeys, true),
            )),
            'home_team' => $this->teamRoster($match->homeTeam),
            'away_team' => $this->teamRoster($match->awayTeam),
            // player_id => { stat_key => value }
            'stats' => $match->stats
                ->groupBy('player_id')
                ->map(fn ($rows) => $rows->mapWithKeys(fn ($s) => [$s->stat_key => $s->value])),
            // team_official_id => { stat_key => value }. Its own map, never
            // merged into `stats`: the two id spaces are separate tables, so one
            // map would be a lookup that cannot tell a coach from a player.
            'official_stats' => $match->officialStats
                ->groupBy('team_official_id')
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
            // The bench carries a role key instead of a number — the label is
            // the sport's and is resolved through the catalogue on the client,
            // the same way a roster row's position is.
            'officials' => $team->officials->map(fn ($o) => [
                'id' => $o->id,
                'full_name' => $o->full_name,
                'role' => $o->role,
            ])->values(),
        ];
    }

    /**
     * Replace every stat row of a match with the given payload.
     *
     * `$officialStats` is `null` for "the caller said nothing about the bench",
     * which leaves those rows untouched, and `[]` for "clear it". Both doors pass
     * the validated key through as-is, so the distinction the `sometimes` rule
     * draws survives all the way to the write — folding null into [] here would
     * make a client that never heard of the bench erase cards somebody else
     * entered, with a green toast.
     *
     * @param  array<int, array{player_id: string, stat_key: string, value: int}>  $stats  already validated by rules()
     * @param  array<int, array{team_official_id: string, stat_key: string, value: int}>|null  $officialStats
     * @return string|null the assist error message, or null when the write went through
     */
    public function replace(GameMatch $match, array $stats, ?array $officialStats = null): ?string
    {
        $match->loadMissing(['homeTeam.players', 'homeTeam.officials', 'awayTeam.players', 'awayTeam.officials']);

        $roster = $this->roster($match);

        // Checked before anything is deleted: a 422 that arrives after the
        // replace looks identical from the outside, but has already thrown the
        // old rows away. The bench is deliberately not part of this check — a
        // coach has no goals to assist.
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

        if ($officialStats !== null) {
            $this->replaceOfficialStats($match, $officialStats);
        }

        return null;
    }

    /**
     * The bench half of the replace, same contract: delete then rewrite.
     *
     * @param  array<int, array{team_official_id: string, stat_key: string, value: int}>  $stats
     */
    protected function replaceOfficialStats(GameMatch $match, array $stats): void
    {
        $bench = $this->bench($match);

        $match->officialStats()->delete();

        foreach ($stats as $entry) {
            if ($entry['value'] < 1 || ! $bench->has($entry['team_official_id'])) {
                continue;
            }

            $match->officialStats()->create([
                'team_id' => $bench[$entry['team_official_id']],
                'team_official_id' => $entry['team_official_id'],
                'stat_key' => $entry['stat_key'],
                'value' => $entry['value'],
            ]);
        }
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
     * team_official_id => team_id, restricted to the two benches present.
     *
     * The same authorization roster() is, for the same reason: an id belonging to
     * any other team's bench is dropped rather than rejected, so a stale editor
     * in an open tab cannot card a coach of a squad that is not playing.
     *
     * @return Collection<string, string>
     */
    protected function bench(GameMatch $match): Collection
    {
        return collect([$match->homeTeam, $match->awayTeam])
            ->filter()
            ->flatMap(fn ($team) => $team->officials->mapWithKeys(fn ($o) => [$o->id => $team->id]));
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
