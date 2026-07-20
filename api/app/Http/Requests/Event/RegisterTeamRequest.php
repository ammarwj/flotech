<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class RegisterTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Offline entry from the organizer side: the team often signed up on
        // paper or over chat and all the organizer has is a name. On the public
        // form the person filling it in *is* the contact, so it stays required
        // there. The organizer routes are the ones nested under {organization};
        // the public ones take {orgSlug}.
        $contact = $this->route('organization') !== null ? 'nullable' : 'required';

        return [
            // Which competition inside the event this team is entering. Ownership
            // (does it belong to this event?) is checked in the controller, where
            // the event is already resolved from the route.
            'category_id' => ['required', 'uuid'],

            'name' => ['required', 'string', 'max:255'],
            'logo_url' => ['nullable', 'string'],
            'contact_name' => [$contact, 'string', 'max:255'],
            'contact_phone' => [$contact, 'string', 'max:20'],

            // Roster and documents may be left for later and completed from the
            // participant dashboard — a manager who doesn't have the squad list
            // in hand yet should still be able to claim a slot. A player row that
            // *is* sent still needs a name.
            'players' => ['nullable', 'array'],
            // Load-bearing, not decoration: validated() drops any key without a
            // rule, so leaving this out strips the id from every row. Sync then
            // reads them all as new, recreates them, and deletes the originals —
            // and player_match_stats cascades on that delete, so an organizer
            // editing a team to add its crest would silently erase every goal it
            // has ever scored. MyTeamController declares the same rule.
            'players.*.id' => ['nullable', 'string'],
            'players.*.full_name' => ['required', 'string', 'max:255'],
            'players.*.jersey_number' => ['nullable', 'string', 'max:5'],
            'players.*.position' => ['nullable', 'string', 'max:50'],
            'players.*.photo_url' => ['nullable', 'string'],

            'documents' => ['nullable', 'array'],
            // Same contract as the roster: without the id every document is
            // re-uploaded as a new row and loses its uploaded_at.
            'documents.*.id' => ['nullable', 'string'],
            'documents.*.file_url' => ['required', 'string'],
            'documents.*.file_name' => ['nullable', 'string', 'max:255'],
            'documents.*.document_type' => ['nullable', 'string', 'max:100'],
        ];
    }
}
