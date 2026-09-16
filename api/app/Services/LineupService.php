<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\MatchLineup;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamOfficial;
use App\Models\User;
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
 * What is NOT validated is the size of the sheet. There is no source of truth for
 * it: `sports` has no lineup_size and Catalog::positions() is a catalogue of
 * positions, not a count. Ownership, duplicates and an upper bound are checked;
 * how many is eleven is left to the referee, which is what the approval step is
 * for. A known gap, stated rather than overlooked.
 */
class LineupService
{
    /** Refuses a sheet longer than any real squad, so a client loop cannot fill the table. */
    public const MAX_PLAYERS = 40;

    public const MAX_OFFICIALS = 20;

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

        // Everything else about the size of a sheet is the referee's call, but a
        // sheet with nobody starting is not a sheet — it is the manager having
        // opened the form and pressed the button.
        if ($lineup->players()->where('role', 'starter')->count() === 0) {
            throw ValidationException::withMessages([
                'players' => 'Isi minimal satu pemain inti sebelum mengirim ke wasit.',
            ]);
        }

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
