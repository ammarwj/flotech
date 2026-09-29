<?php

namespace App\Services;

use App\Models\EventPersonnel;
use App\Models\GameMatch;
use App\Models\MatchLineup;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamOfficial;
use App\Models\User;
use App\Support\SquadRules;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The team sheet a manager hands in before kick-off: who starts, who is on the
 * bench, and which officials sit with them.
 *
 * Same full-list contract as TeamRosterService — a row carrying an `id` is an
 * update, a row without one is new, and anything the client left out is deleted.
 * The rules live here rather than in the controller because a second write path
 * is already foreseeable (an organizer filling a sheet in for a manager who never
 * logged in), and two copies of "is this player even on that team?" is how one of
 * them ends up letting a ringer through.
 *
 * How many may be named is `SquadRules`, resolved per category from the sport's
 * own numbers and the event's override — football eleven and seven, futsal five
 * and nine. It replaces what this docblock used to call a known gap ("how many is
 * eleven is left to the referee"): the answer now has a source of truth, so there
 * is no reason to make the referee the one who counts.
 *
 * The count is asked with two different operators, and the difference is the
 * point. `sync()` enforces the ceiling, `submit()` enforces the exact number: a
 * manager filling the sheet in while waiting to hear from a player must be able
 * to save eight, and the sheet the referee receives must be a full one. That is
 * also why it is one number rather than a min and a max — see the invariant on
 * SquadRules.
 *
 * A sport without a team sheet (`enabled` false — every set sport) is not
 * counted at all. MAX_PLAYERS below still applies there, and remains what stops
 * a client loop filling the table.
 *
 * Suspensions are read here, and the direction of that read is the whole of why
 * it is allowed. DisciplineService's own docblock says `match_lineups` is
 * deliberately not read *there* — a sheet is handed in before kick-off and a
 * named substitute may never come on, so reading it would claim a ban was served
 * by somebody who sat and watched. This reverses the arrow rather than breaking
 * that rule: the sheet asks the bans, the bans never ask the sheet, and how a ban
 * is discharged is still "the team's next official fixture".
 */
class LineupService
{
    /** Refuses a sheet longer than any real squad, so a client loop cannot fill the table. */
    public const MAX_PLAYERS = 40;

    public const MAX_OFFICIALS = 20;

    public function __construct(protected DisciplineService $discipline) {}

    /**
     * The one row for this team in this fixture, created on first read.
     *
     * Created rather than returned null so every caller — the editor, the
     * referee's list, the sheet — has one thing to read, and `unique(match_id,
     * team_id)` is what stops two of them existing.
     */
    public function findOrCreate(GameMatch $match, Team $team): MatchLineup
    {
        return MatchLineup::firstOrCreate(
            ['match_id' => $match->id, 'team_id' => $team->id],
            ['status' => 'draft'],
        );
    }

    /**
     * Replace the sheet's contents with what the client sent.
     *
     * @param  array<int, array<string, mixed>>  $players
     * @param  array<int, array<string, mixed>>  $officials
     *
     * @throws ValidationException
     */
    public function sync(GameMatch $match, Team $team, array $players, array $officials): MatchLineup
    {
        $lineup = $this->findOrCreate($match, $team);

        $this->assertEditable($lineup);
        $this->assertPlayersOwned($team, $players);
        // Ceiling only here — a half-filled draft is a legitimate save. The exact
        // count is submit()'s question, see the note on the class.
        $this->assertSquadSize($match, $players, exact: false);
        $this->assertNoBannedPlayers($match, $team, array_column($players, 'player_id'));
        $this->assertOfficialsOwned($team, $officials);

        $keepIds = [];

        foreach ($players as $i => $row) {
            $attrs = [
                'player_id' => $row['player_id'],
                'role' => $row['role'] ?? 'substitute',
                // The order the manager typed is the order the sheet prints.
                'sort_order' => $i,
            ];

            // By row id when the client still has it, by player otherwise. The
            // fallback is not belt-and-braces: `unique(lineup_id, player_id)`
            // means the player *is* the row's identity, and an editor that
            // rebuilds its list — which is what moving somebody from the bench
            // to the starting XI looks like on the wire — sends the same player
            // back with no id and would hit that index as a 500.
            $existing = ! empty($row['id'])
                ? $lineup->players()->whereKey($row['id'])->first()
                : $lineup->players()->where('player_id', $row['player_id'])->first();

            if ($existing) {
                $existing->update($attrs);
                $named = $existing;
            } else {
                $named = $lineup->players()->create($attrs);
            }

            $keepIds[] = $named->id;
        }

        $lineup->players()->whereKeyNot($keepIds)->delete();

        $keepOfficialIds = [];

        foreach ($officials as $i => $row) {
            $attrs = [
                'team_official_id' => $row['team_official_id'],
                'sort_order' => $i,
            ];

            // Same fallback, same index, same reason as the players above.
            $existing = ! empty($row['id'])
                ? $lineup->officials()->whereKey($row['id'])->first()
                : $lineup->officials()->where('team_official_id', $row['team_official_id'])->first();

            if ($existing) {
                $existing->update($attrs);
                $named = $existing;
            } else {
                $named = $lineup->officials()->create($attrs);
            }

            $keepOfficialIds[] = $named->id;
        }

        $lineup->officials()->whereKeyNot($keepOfficialIds)->delete();

        return $lineup->refresh();
    }

    /**
     * Hand the sheet to the referee.
     *
     * From here the manager cannot change it — that lock is the whole of what the
     * approval means. `rejected` is a legal starting point: a sheet sent back is
     * meant to be fixed and sent again.
     *
     * @throws ValidationException
     */
    public function submit(MatchLineup $lineup, User $by): MatchLineup
    {
        $this->assertEditable($lineup);

        // Asked of the stored rows, and with `exact` — this is the hand-in, so
        // the sheet has to be a full one. It also covers what used to be a
        // separate "at least one starter" guard here: a sport with a squad
        // rulebook demands its exact eleven, and a sport without one (every set
        // sport) still cannot hand in an empty sheet.
        $stored = $lineup->players()->get(['player_id', 'role'])
            ->map(fn ($row) => ['player_id' => $row->player_id, 'role' => $row->role])
            ->all();

        $this->assertSquadSize($lineup->match()->with('category.event')->first(), $stored, exact: true);

        // Asked again, of the stored rows rather than of a payload — and not a
        // duplicate of the check in sync(). A draft saved last week passed that
        // one against the cards known at the time; the fixture that issued the
        // ban may only have been confirmed since. The sheet being handed in is
        // the one that has to be legal now.
        $this->assertNoBannedPlayers(
            $lineup->match,
            $lineup->team,
            $lineup->players()->pluck('player_id')->all(),
        );

        $lineup->update([
            'status' => 'submitted',
            'submitted_at' => Carbon::now(),
            'submitted_by' => $by->id,
            // The previous rejection belongs to the version that was rejected;
            // carrying it forward would leave the referee reading their own old
            // complaint against a sheet that answered it.
            'note' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);

        return $lineup->refresh();
    }

    /**
     * The referee accepts the sheet. From here nobody edits it again.
     *
     * `reviewed_by` names an EventPersonnel row, not a user: the person signing
     * is signing as the referee of this event, and the same human refereeing a
     * second tournament is a second row. It is also what keeps the reference
     * valid after the account is unlinked — deleting a personnel row is what
     * ends the assignment, and `nullOnDelete` then says so honestly.
     *
     * @throws ValidationException
     */
    public function approve(MatchLineup $lineup, EventPersonnel $by): MatchLineup
    {
        $this->assertSubmitted($lineup);

        $lineup->update([
            'status' => 'approved',
            'reviewed_at' => Carbon::now(),
            'reviewed_by' => $by->id,
            'note' => null,
        ]);

        return $lineup->refresh();
    }

    /**
     * The referee sends it back, with a reason.
     *
     * The reason is required by the controller's rules and kept here: a refusal
     * without one leaves the manager guessing at what to change, and the editor
     * already has a place to print it above the form.
     *
     * @throws ValidationException
     */
    public function reject(MatchLineup $lineup, EventPersonnel $by, string $note): MatchLineup
    {
        $this->assertSubmitted($lineup);

        $lineup->update([
            'status' => 'rejected',
            'reviewed_at' => Carbon::now(),
            'reviewed_by' => $by->id,
            'note' => $note,
        ]);

        return $lineup->refresh();
    }

    /**
     * Both verdicts start from `submitted` and nowhere else.
     *
     * A draft has not been handed in, so there is nothing to answer. An approved
     * sheet is deliberately terminal: the print gate downstream reads
     * `approved`, and a route that could take it back would also reopen editing
     * on a sheet that may already be printed and on the table. A referee who
     * approved in error is the organizer's problem, not a cancel button.
     *
     * @throws ValidationException
     */
    protected function assertSubmitted(MatchLineup $lineup): void
    {
        if ($lineup->status === 'submitted') {
            return;
        }

        throw ValidationException::withMessages([
            'status' => $lineup->status === 'approved'
                ? 'Susunan pemain sudah disetujui dan tidak bisa diubah lagi.'
                : 'Susunan pemain belum dikirim manajer, jadi belum bisa di-acc.',
        ]);
    }

    /**
     * @throws ValidationException
     */
    protected function assertEditable(MatchLineup $lineup): void
    {
        if ($lineup->isEditable()) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => $lineup->status === 'approved'
                ? 'Susunan pemain sudah disetujui wasit dan tidak bisa diubah.'
                : 'Susunan pemain sedang menunggu acc wasit dan tidak bisa diubah.',
        ]);
    }

    /**
     * Every named player is on that team's own roster, and named once.
     *
     * Mirrors RubberController::validateLineup(): the same question, asked of the
     * same table, so a lineup here cannot admit a player a partai lineup would
     * refuse. The unique index on (lineup_id, player_id) is the last net, but it
     * would surface as a 500 rather than a field error.
     *
     * @param  array<int, array<string, mixed>>  $players
     *
     * @throws ValidationException
     */
    protected function assertPlayersOwned(Team $team, array $players): void
    {
        if ($players === []) {
            return;
        }

        if (count($players) > self::MAX_PLAYERS) {
            throw ValidationException::withMessages([
                'players' => 'Susunan pemain terlalu panjang.',
            ]);
        }

        $ids = array_map(fn ($row) => $row['player_id'], $players);

        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages([
                'players' => 'Ada pemain yang didaftarkan dua kali.',
            ]);
        }

        $owned = Player::where('team_id', $team->id)->whereKey($ids)->count();

        if ($owned !== count($ids)) {
            throw ValidationException::withMessages([
                'players' => 'Pemain harus berasal dari tim yang bertanding.',
            ]);
        }
    }

    /**
     * How many are named, against what this category allows.
     *
     * One method for both doors, because the two questions differ by one
     * operator and nothing else — written twice they would drift, and the drift
     * would be a manager who can save a sheet the hand-in then refuses for a
     * reason neither screen states.
     *
     * Substitutes are capped identically at both doors: `max_substitutes` is a
     * ceiling in its own right, never a target, so there is nothing for `exact`
     * to change about it. A bench may legitimately be short — that is a squad
     * that has run out of players, not a sheet that is incomplete.
     *
     * A sport without a team sheet is skipped entirely rather than falling back
     * to the eleven in DEFAULTS: those numbers describe running-score sports,
     * and applied to badminton they would refuse every sheet ever filed. This is
     * the one place where "no rulebook" must not read as "the default rulebook",
     * which is why SquadRules answers `enabled` from the catalogue rather than
     * from whether a config row happens to exist.
     *
     * @param  array<int, array<string, mixed>>  $players
     *
     * @throws ValidationException
     */
    protected function assertSquadSize(GameMatch $match, array $players, bool $exact): void
    {
        $rules = SquadRules::forCategory($match->category);

        if (! $rules->enabled) {
            // Still not a sheet with nobody on it. The check the squad rules
            // took over above has to survive for the sports they do not cover,
            // or a set-sport manager hands in an empty form.
            if ($exact && $players === []) {
                throw ValidationException::withMessages([
                    'players' => 'Isi minimal satu pemain sebelum mengirim ke wasit.',
                ]);
            }

            return;
        }

        $starters = count(array_filter($players, fn ($row) => ($row['role'] ?? 'substitute') === 'starter'));
        $substitutes = count($players) - $starters;

        if ($exact && $starters !== $rules->starters) {
            throw ValidationException::withMessages([
                'players' => "Susunan pemain harus berisi tepat {$rules->starters} pemain inti sebelum dikirim ke wasit. Saat ini {$starters}.",
            ]);
        }

        if (! $exact && $starters > $rules->starters) {
            throw ValidationException::withMessages([
                'players' => "Maksimal {$rules->starters} pemain inti untuk kategori ini.",
            ]);
        }

        if ($substitutes > $rules->maxSubstitutes) {
            throw ValidationException::withMessages([
                'players' => $rules->maxSubstitutes === 0
                    ? 'Kategori ini tidak memakai pemain cadangan.'
                    : "Maksimal {$rules->maxSubstitutes} pemain cadangan untuk kategori ini.",
            ]);
        }
    }

    /**
     * The bans this team carries into this fixture, as the editor draws them.
     *
     * Filtered to one team and one fixture here rather than in the browser: the
     * manager has no reason to be handed the opponent's suspensions, and a
     * client-side filter is one a second client would have to reimplement.
     *
     * Three things are easy to get wrong reading DisciplineService's payload, and
     * all three are handled here so no caller repeats them:
     *  - `matches` is a stdClass — cast before indexing (it is cast there so an
     *    empty map is `{}` in JSON rather than `[]`).
     *  - `status: 'served'` entries are history, recorded against fixtures
     *    already played. Only 'upcoming' refuses anybody.
     *  - `bans_remaining` counts the fixture the entry appears on, so it is not
     *    "what is left after this one".
     *
     * @return array{bans: list<array<string, mixed>>, rules: array<string, mixed>|null}
     */
    public function bansFor(GameMatch $match, Team $team): array
    {
        $payload = $this->disciplineFor($match);

        if (! $payload['enabled']) {
            return ['bans' => [], 'rules' => null];
        }

        $bans = collect(((array) $payload['matches'])[$match->id] ?? [])
            ->filter(fn (array $ban) => $ban['status'] === 'upcoming' && $ban['team_id'] === $team->id)
            ->values()
            ->all();

        return ['bans' => $bans, 'rules' => $payload['rules']];
    }

    /**
     * Nobody named on the sheet is serving a ban in this fixture.
     *
     * Named out loud in the message: "ada pemain terskors" sends the manager back
     * to a roster of twenty to work out which one, and the editor's own panel is
     * built from the same list.
     *
     * @param  array<int, string>  $playerIds
     *
     * @throws ValidationException
     */
    protected function assertNoBannedPlayers(GameMatch $match, Team $team, array $playerIds): void
    {
        if ($playerIds === []) {
            return;
        }

        $names = collect($this->bansFor($match, $team)['bans'])
            ->filter(fn (array $ban) => in_array($ban['player_id'], $playerIds, true))
            ->pluck('player_name')
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'players' => $names->count() === 1
                ? "{$names->first()} sedang menjalani larangan bermain dan tidak bisa dimainkan di laga ini."
                : $names->join(', ', ' dan ').' sedang menjalani larangan bermain dan tidak bisa dimainkan di laga ini.',
        ]);
    }

    /**
     * Asked fresh every time, and deliberately not memoised on this instance.
     *
     * A per-category memo was written here first, on the reasoning that
     * forCategory() sweeps the whole category and sync() plus submit() in one
     * request would run it twice. It is wrong, and quietly: Laravel keeps the
     * resolved controller — and the services injected into it — alive across
     * requests, so the memo outlived the request that filled it. An organizer
     * correcting three yellows down to one got a sheet still refusing the
     * player, over cards no longer in the database. That is precisely the bug
     * DisciplineService's docblock says it derives suspensions to avoid; a memo
     * with a lifetime it does not control is a stored ban under another name.
     * Two queries and an O(n) pass is what it costs to always be right.
     *
     * @return array{enabled: bool, rules: array<string, mixed>|null, players: array<int, array<string, mixed>>, matches: object}
     */
    protected function disciplineFor(GameMatch $match): array
    {
        return $this->discipline->forCategory($match->category);
    }

    /**
     * The same question for the bench. Its own method, and its own table, for the
     * same reason the tables are separate: an official is not a player, and a
     * check that took both from one list would have to branch on which column was
     * filled.
     *
     * @param  array<int, array<string, mixed>>  $officials
     *
     * @throws ValidationException
     */
    protected function assertOfficialsOwned(Team $team, array $officials): void
    {
        if ($officials === []) {
            return;
        }

        if (count($officials) > self::MAX_OFFICIALS) {
            throw ValidationException::withMessages([
                'officials' => 'Daftar ofisial terlalu panjang.',
            ]);
        }

        $ids = array_map(fn ($row) => $row['team_official_id'], $officials);

        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages([
                'officials' => 'Ada ofisial yang didaftarkan dua kali.',
            ]);
        }

        $owned = TeamOfficial::where('team_id', $team->id)->whereKey($ids)->count();

        if ($owned !== count($ids)) {
            throw ValidationException::withMessages([
                'officials' => 'Ofisial harus berasal dari tim yang bertanding.',
            ]);
        }
    }
}
