<?php

namespace App\Http\Requests\Event;

/**
 * Validation rules for the roster / bench / documents / custom-field block that
 * a team payload carries, shared by RegisterTeamRequest (public register plus
 * both organizer routes) and MyTeamController@update (the participant editing
 * their own team).
 *
 * Those two used to declare the same rules word for word, which is how the
 * documents block ended up in two places that both had to be remembered — the
 * same reason EventCategoryRules exists.
 *
 * What is *not* here: whether a custom field is required, whether a document
 * type is one this event defined, whether a player row is complete. None of
 * that is knowable without the event, and a FormRequest cannot resolve one from
 * three different shapes of route. It lives in TeamRosterService, next to the
 * position and role checks, for exactly that reason.
 */
class TeamPayloadRules
{
    /**
     * @param  string  $presence  'nullable' (register: the list may be skipped
     *                            entirely and completed later) or 'sometimes'
     *                            (update: an omitted key must not wipe the list,
     *                            an empty array must)
     * @return array<string, mixed>
     */
    public static function make(string $presence): array
    {
        return [
            // Roster and documents may be left for later and completed from the
            // participant dashboard — a manager who doesn't have the squad list
            // in hand yet should still be able to claim a slot. A player row that
            // *is* sent still needs a name, and once it has one it must be
            // complete (TeamRosterService enforces that against the event's
            // registration form).
            'players' => [$presence, 'array'],
            // Load-bearing, not decoration: validated() drops any key without a
            // rule, so leaving this out strips the id from every row. Sync then
            // reads them all as new, recreates them, and deletes the originals —
            // and player_match_stats cascades on that delete, so an organizer
            // editing a team to add its crest would silently erase every goal it
            // has ever scored.
            'players.*.id' => ['nullable', 'string'],
            'players.*.full_name' => ['required', 'string', 'max:255'],
            'players.*.jersey_number' => ['nullable', 'string', 'max:5'],
            'players.*.position' => ['nullable', 'string', 'max:50'],
            'players.*.photo_url' => ['nullable', 'string'],
            // Answers to the player fields this event defined. Free-form here;
            // which keys exist and which are required is the event's business.
            'players.*.custom_fields' => ['nullable', 'array'],

            // A player's documents travel nested inside their row, not as a flat
            // list keyed by player_id: a player being added for the first time
            // has no id yet when the request is composed, so ownership has to
            // come from the position in the payload.
            'players.*.documents' => ['nullable', 'array', 'max:20'],
            'players.*.documents.*.id' => ['nullable', 'string'],
            'players.*.documents.*.file_url' => ['required', 'string'],
            'players.*.documents.*.file_name' => ['nullable', 'string', 'max:255'],
            'players.*.documents.*.document_type' => ['nullable', 'string', 'max:100'],

            // The bench: pelatih, manajer, ofisial. Optional everywhere and for
            // every participant_type — a singles entrant may bring a coach too.
            'officials' => [$presence, 'array', 'max:20'],
            // Same contract as players.*.id: without it every official is read
            // as new, recreated, and the photo already uploaded for that row is
            // orphaned.
            'officials.*.id' => ['nullable', 'string'],
            'officials.*.full_name' => ['required', 'string', 'max:255'],
            'officials.*.role' => ['nullable', 'string', 'max:30'],
            'officials.*.photo_url' => ['nullable', 'string'],

            // The team's own documents — a mandate letter, a club deed. A
            // player's KTP is not here; it is nested in their roster row.
            'documents' => [$presence, 'array', 'max:20'],
            // Same contract as the roster: without the id every document is
            // re-uploaded as a new row and loses its uploaded_at.
            'documents.*.id' => ['nullable', 'string'],
            'documents.*.file_url' => ['required', 'string'],
            'documents.*.file_name' => ['nullable', 'string', 'max:255'],
            'documents.*.document_type' => ['nullable', 'string', 'max:100'],

            // Answers to the team fields this event defined.
            'custom_fields' => ['nullable', 'array'],
        ];
    }
}
