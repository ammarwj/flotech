<?php

namespace App\Services;

use App\Jobs\PurgeMediaJob;
use App\Models\Player;
use App\Models\Team;
use App\Support\RegistrationForm;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Roster, bench and document lists, kept in sync from two places: the participant
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
        $this->assertRosterSize($team, $players);
        $this->assertPositionsExist($team, $players);

        $form = RegistrationForm::forEvent($team->event);
        $keepIds = [];
        $errors = [];

        foreach ($players as $i => $row) {
            $attrs = [
                'full_name' => $row['full_name'],
                'jersey_number' => $row['jersey_number'] ?? null,
                'position' => $row['position'] ?? null,
                'photo_url' => $row['photo_url'] ?? null,
            ];

            // Absent means "not sent by this client", which must leave whatever
            // is stored alone; an empty array means "cleared".
            if (array_key_exists('custom_fields', $row)) {
                $attrs['custom_fields'] = $this->cleanAnswers($form->playerFields, $row['custom_fields']);
            }

            $existing = ! empty($row['id'])
                ? $team->players()->whereKey($row['id'])->first()
                : null;

            if ($existing) {
                $existing->update($attrs);
                $player = $existing;
            } else {
                $player = $team->players()->create($attrs);
            }

            $keepIds[] = $player->id;

            if (array_key_exists('documents', $row)) {
                $errors += $this->syncDocumentRows($team, $row['documents'] ?? [], $player, "players.{$i}.documents");
            }

            // After the sync, never before: the documents that make this row
            // complete may be ones already in storage, sent back as rows carrying
            // an id. Asserting off the payload alone would reject a participant
            // who opens their edit form, changes nothing, and saves.
            $errors += $this->rowErrors($form, $player, $row, $i);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $stale = $team->players()->whereKeyNot($keepIds)->pluck('photo_url')->all();
        $team->players()->whereKeyNot($keepIds)->delete();
        $this->purgePruned($stale, $team->players()->pluck('photo_url')->all());

        $this->syncDerivedName($team, $players);
    }

    /**
     * Is this player row complete enough to keep?
     *
     * The rule the organizer asked for: a roster may be left empty and filled in
     * later, but once a name is typed that row has to carry the fields and
     * documents the event requires. It falls out of the loop shape rather than
     * an `if` — nothing sent means nothing checked — so "may be completed later"
     * cannot be forgotten when this method changes.
     *
     * The caller throws, so a half-finished row is never written: the whole
     * registration rolls back and the name it was rejected for is not in the
     * database at all.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function rowErrors(RegistrationForm $form, Player $player, array $row, int $i): array
    {
        $errors = $this->answerErrors(
            $form->playerFields,
            $row['custom_fields'] ?? $player->custom_fields ?? [],
            "players.{$i}.custom_fields",
        );

        // Read back rather than counted from the payload, for the same reason
        // the assert runs after the sync.
        $uploaded = $player->documents()->pluck('document_type')->filter()->all();

        foreach ($form->playerDocuments as $doc) {
            if ($doc['required'] && ! in_array($doc['key'], $uploaded, true)) {
                $errors["players.{$i}.documents"] = "Dokumen \"{$doc['label']}\" wajib diunggah untuk {$player->full_name}.";
            }
        }

        return $errors;
    }

    /**
     * A singles entrant is one player and a doubles entrant is exactly two —
     * the category says so, and there is nothing else the entry could be.
     *
     * Note this also makes the roster *mandatory* for those two, unlike a squad
     * which may claim a slot and fill its list in later: the entry has no name
     * of its own until the players are known (see syncDerivedName).
     *
     * @param  array<int, array<string, mixed>>  $players
     *
     * @throws ValidationException
     */
    private function assertRosterSize(Team $team, array $players): void
    {
        $size = $team->category?->rosterSize();

        if ($size === null || count($players) === $size) {
            return;
        }

        throw ValidationException::withMessages([
            'players' => $size === 1
                ? 'Kategori tunggal diisi tepat 1 pemain.'
                : 'Kategori ganda diisi tepat 2 pemain.',
        ]);
    }

    /**
     * A singles/doubles entry has no team name — it *is* its players ("Dimas",
     * "Dimas / Ammar"). Deriving it here, on the one write path all three roster
     * flows share, is what lets every reader stay untouched: standings, brackets,
     * match cards, certificates and the public pages all keep reading
     * `teams.name` and get the right thing.
     *
     * @param  array<int, array<string, mixed>>  $players
     */
    private function syncDerivedName(Team $team, array $players): void
    {
        if ($team->category?->rosterSize() === null) {
            return;
        }

        $name = implode(' / ', array_map(
            fn ($row) => trim((string) $row['full_name']),
            $players,
        ));

        if ($name !== '' && $name !== $team->name) {
            $team->update(['name' => $name]);
        }
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
     * The bench, same contract as the roster. No size rule: a team may have no
     * officials at all, and a singles entrant may still bring a coach — the
     * count is nobody's business but the organizer's.
     *
     * Officials live in their own table precisely so this method needs neither
     * assertRosterSize() nor syncDerivedName(); see the migration for why.
     *
     * @param  array<int, array<string, mixed>>  $officials
     */
    public function syncOfficials(Team $team, array $officials): void
    {
        $this->assertRolesExist($team, $officials);

        $keepIds = [];

        foreach ($officials as $order => $row) {
            $attrs = [
                'full_name' => $row['full_name'],
                'role' => $row['role'] ?? null,
                'photo_url' => $row['photo_url'] ?? null,
                // The order they were typed in is the order they are shown.
                'sort_order' => $order,
            ];

            $existing = ! empty($row['id'])
                ? $team->officials()->whereKey($row['id'])->first()
                : null;

            if ($existing) {
                $existing->update($attrs);
                $keepIds[] = $existing->id;
            } else {
                $keepIds[] = $team->officials()->create($attrs)->id;
            }
        }

        $stale = $team->officials()->whereKeyNot($keepIds)->pluck('photo_url')->all();
        $team->officials()->whereKeyNot($keepIds)->delete();
        $this->purgePruned($stale, $team->officials()->pluck('photo_url')->all());
    }

    /**
     * A role must be one the admin defined for this event's sport (see
     * sport_official_roles). Guarded here for the same reason positions are:
     * this is the one write path all three flows share.
     *
     * @param  array<int, array<string, mixed>>  $officials
     *
     * @throws ValidationException
     */
    private function assertRolesExist(Team $team, array $officials): void
    {
        $allowed = Catalog::officialRoleKeys($team->event?->sport_type);
        $errors = [];

        foreach ($officials as $i => $row) {
            $role = $row['role'] ?? null;

            if ($role !== null && $role !== '' && ! in_array($role, $allowed, true)) {
                $errors["officials.{$i}.role"] = 'Peran ofisial tidak dikenali untuk cabang olahraga ini.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The team's own documents — a mandate letter, a club deed. A player's KTP
     * arrives nested in their roster row and is handled by syncPlayers().
     *
     * The file is already in storage by the time it gets here — only its
     * metadata travels through this method.
     *
     * @param  array<int, array<string, mixed>>  $documents
     *
     * @throws ValidationException
     */
    public function syncDocuments(Team $team, array $documents): void
    {
        $errors = $this->syncDocumentRows($team, $documents, null, 'documents');

        $form = RegistrationForm::forEvent($team->event);
        $uploaded = $team->documents()->pluck('document_type')->filter()->all();

        foreach ($form->teamDocuments as $doc) {
            if ($doc['required'] && ! in_array($doc['key'], $uploaded, true)) {
                $errors['documents'] = "Dokumen \"{$doc['label']}\" wajib diunggah.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Sync one scope of a team's documents: the team's own ($player null) or a
     * single player's.
     *
     * Scoping the *pruning* is the load-bearing part. This used to sweep every
     * row the team had; called once per player it would delete the team's
     * documents and every other player's on each pass, taking their stored files
     * with them through purgePruned().
     *
     * Rows carrying an id are updated, not merely kept. They used to be skipped,
     * which quietly made document_type write-once: an organizer who filed a KTP
     * under the wrong slot had no way to correct it.
     *
     * Errors are returned rather than thrown because syncPlayers() collects them
     * across the whole roster — one 422 listing every incomplete row beats the
     * participant discovering them one save at a time.
     *
     * @param  array<int, array<string, mixed>>  $documents
     * @return array<string, string>
     */
    private function syncDocumentRows(Team $team, array $documents, ?Player $player, string $errorKey): array
    {
        $form = RegistrationForm::forEvent($team->event);
        $allowed = array_column($form->documentsFor($player ? 'player' : 'team'), 'key');

        // Rebuilt on each use rather than held: the same query is run three
        // times below and an Eloquent builder is not reusable after execution.
        $scope = $player
            ? fn () => $team->allDocuments()->where('player_id', $player->id)
            : fn () => $team->allDocuments()->whereNull('player_id');

        $keepIds = [];
        $errors = [];

        foreach ($documents as $i => $row) {
            $type = $row['document_type'] ?? null;

            if ($error = $this->documentTypeError($form, $type, $allowed, $row['file_name'] ?? $row['file_url'])) {
                $errors["{$errorKey}.{$i}.document_type"] = $error;

                continue;
            }

            $attrs = [
                'player_id' => $player?->id,
                'file_url' => $row['file_url'],
                'file_name' => $row['file_name'] ?? null,
                'document_type' => $type,
            ];

            $existing = ! empty($row['id']) ? $scope()->whereKey($row['id'])->first() : null;

            if ($existing) {
                $existing->update($attrs);
                $keepIds[] = $existing->id;
            } else {
                $keepIds[] = $scope()->create($attrs + ['uploaded_at' => Carbon::now()])->id;
            }
        }

        $stale = $scope()->whereKeyNot($keepIds)->pluck('file_url')->all();
        $scope()->whereKeyNot($keepIds)->delete();

        // Compared against *both* scopes: a file moved from a team slot onto a
        // player's in one request is not stale, and from here the two halves of
        // that move are the same request.
        $this->purgePruned($stale, $team->allDocuments()->pluck('file_url')->all());

        return $errors;
    }

    /**
     * A document must name a slot this event defined, and its file must be one
     * of the kinds that slot accepts.
     *
     * This is also what makes "no documents defined ⇒ nothing to upload" true on
     * the server and not just in the UI: with an empty schema every type is
     * unknown, so an older client cannot post stray files into the event.
     */
    private function documentTypeError(RegistrationForm $form, ?string $type, array $allowed, ?string $file): ?string
    {
        if ($type === null || $type === '') {
            // Documents predating this feature carry no type, and an event that
            // defines none has nothing to check against.
            return $form->documentKeys() === [] ? null : 'Jenis dokumen wajib dipilih.';
        }

        if (! in_array($type, $allowed, true)) {
            return 'Jenis dokumen tidak dikenali untuk event ini.';
        }

        $accept = $form->document($type)['accept'] ?? [];
        $ext = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;

        // A webp is what UploadController turns every accepted image into, so an
        // extension check that refused it would reject the files we produced.
        if ($ext !== '' && $ext !== 'webp' && ! in_array($ext, $accept, true)) {
            return 'Format berkas tidak diterima untuk jenis dokumen ini.';
        }

        return null;
    }

    /**
     * Write a team's answers to the custom fields this event defined.
     *
     * Separate from syncDocuments() because the two are separately optional: a
     * participant fixing a typo in their team name sends neither, and neither
     * absence may wipe the other's data.
     *
     * @param  array<string, mixed>|null  $answers
     *
     * @throws ValidationException
     */
    public function applyCustomFields(Team $team, ?array $answers): void
    {
        $form = RegistrationForm::forEvent($team->event);

        $errors = $this->answerErrors($form->teamFields, $answers ?? $team->custom_fields ?? [], 'custom_fields');

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($answers !== null) {
            $team->update(['custom_fields' => $this->cleanAnswers($form->teamFields, $answers)]);
        }
    }

    /**
     * Keep only answers to fields that exist, as strings.
     *
     * Dropping unknown keys rather than rejecting them is deliberate: an
     * organizer may remove a field that is still sitting in a browser tab
     * somewhere, and that should not turn into a 422 the participant cannot act
     * on. Removing a field that already has answers is blocked at the schema
     * end (EventController), which is where the organizer can actually respond.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  mixed  $answers
     * @return array<string, string>
     */
    private function cleanAnswers(array $fields, $answers): array
    {
        if (! is_array($answers)) {
            return [];
        }

        $clean = [];

        foreach ($fields as $field) {
            $value = $answers[$field['key']] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                $clean[$field['key']] = trim((string) $value);
            }
        }

        return $clean;
    }

    /**
     * Required fields must be answered, and a select's answer must be one of its
     * options — a client is free to render a text box, but the stored value has
     * to mean something to the organizer reading the export.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  mixed  $answers
     * @return array<string, string>
     */
    private function answerErrors(array $fields, $answers, string $prefix): array
    {
        $answers = is_array($answers) ? $answers : [];
        $errors = [];

        foreach ($fields as $field) {
            $value = $answers[$field['key']] ?? null;
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($value === '') {
                if ($field['required']) {
                    $errors["{$prefix}.{$field['key']}"] = "{$field['label']} wajib diisi.";
                }

                continue;
            }

            if ($field['type'] === 'select' && ! in_array($value, $field['options'], true)) {
                $errors["{$prefix}.{$field['key']}"] = "Pilihan {$field['label']} tidak dikenali.";
            }
        }

        return $errors;
    }

    /**
     * Delete the stored files of rows this sync just pruned.
     *
     * A value that is still on the team afterwards is *not* stale, even though
     * the row holding it was: a client may move a photo onto a new roster row
     * while dropping the one it came from, and from here the two are the same
     * request. Deleting it would take out the photo just saved.
     *
     * All three callers run inside the registration transactions, hence
     * afterCommit() — a rollback must not leave surviving rows pointing at
     * files that are already gone.
     *
     * @param  array<int, string|null>  $stale
     * @param  array<int, string|null>  $remaining
     */
    private function purgePruned(array $stale, array $remaining): void
    {
        $gone = array_diff(array_filter($stale), array_filter($remaining));

        if ($gone !== []) {
            PurgeMediaJob::dispatch(array_values($gone))->afterCommit();
        }
    }
}
