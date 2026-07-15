<?php

namespace App\Services;

use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Roster and document lists, kept in sync from two places: the participant
 * editing their own team, and the organizer maintaining a team they entered by
 * hand (offline registration). Both send the full list, so the rules live here
 * once instead of drifting apart in two controllers.
 *
 * Contract: a row carrying an `id` is an update, a row without one is new, and
 * anything the client left out is deleted.
 */
class TeamRosterService
{
    /**
     * @param  array<int, array<string, mixed>>  $players
     */
    public function syncPlayers(Team $team, array $players): void
    {
        $this->assertPositionsExist($team, $players);

        $keepIds = [];

        foreach ($players as $row) {
            $attrs = [
                'full_name' => $row['full_name'],
                'jersey_number' => $row['jersey_number'] ?? null,
                'position' => $row['position'] ?? null,
                'photo_url' => $row['photo_url'] ?? null,
            ];

            $existing = ! empty($row['id'])
                ? $team->players()->whereKey($row['id'])->first()
                : null;

            if ($existing) {
                $existing->update($attrs);
                $keepIds[] = $existing->id;
            } else {
                $keepIds[] = $team->players()->create($attrs)->id;
            }
        }

        $team->players()->whereKeyNot($keepIds)->delete();
    }

    /**
     * A position must be one the admin defined for this event's sport (see
     * sport_positions). Guarded here rather than in the FormRequests because
     * this is the one write path all three roster flows share, and none of them
     * knows the sport without resolving the event from its own shape of route.
     *
     * @param  array<int, array<string, mixed>>  $players
     *
     * @throws ValidationException
     */
    private function assertPositionsExist(Team $team, array $players): void
    {
        $allowed = Catalog::positionKeys($team->event?->sport_type);
        $errors = [];

        foreach ($players as $i => $row) {
            $position = $row['position'] ?? null;

            if ($position !== null && $position !== '' && ! in_array($position, $allowed, true)) {
                $errors["players.{$i}.position"] = 'Posisi tidak dikenali untuk cabang olahraga ini.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The file is already in storage by the time it gets here — only its
     * metadata travels through this method.
     *
     * @param  array<int, array<string, mixed>>  $documents
     */
    public function syncDocuments(Team $team, array $documents): void
    {
        $keepIds = [];

        foreach ($documents as $row) {
            $existing = ! empty($row['id'])
                ? $team->documents()->whereKey($row['id'])->first()
                : null;

            if ($existing) {
                $keepIds[] = $existing->id;

                continue;
            }

            $keepIds[] = $team->documents()->create([
                'file_url' => $row['file_url'],
                'file_name' => $row['file_name'] ?? null,
                'document_type' => $row['document_type'] ?? null,
                'uploaded_at' => Carbon::now(),
            ])->id;
        }

        $team->documents()->whereKeyNot($keepIds)->delete();
    }
}
