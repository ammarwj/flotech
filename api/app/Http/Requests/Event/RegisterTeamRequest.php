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
        return [
            'name' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'jersey_color' => ['nullable', 'string', 'max:20'],
            'logo_url' => ['nullable', 'string'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:20'],

            // Roster and documents may be left for later and completed from the
            // participant dashboard — a manager who doesn't have the squad list
            // in hand yet should still be able to claim a slot. A player row that
            // *is* sent still needs a name.
            'players' => ['nullable', 'array'],
            'players.*.full_name' => ['required', 'string', 'max:255'],
            'players.*.jersey_number' => ['nullable', 'string', 'max:5'],
            'players.*.position' => ['nullable', 'string', 'max:50'],

            'documents' => ['nullable', 'array'],
            'documents.*.file_url' => ['required', 'string'],
            'documents.*.file_name' => ['nullable', 'string', 'max:255'],
            'documents.*.document_type' => ['nullable', 'string', 'max:100'],
        ];
    }
}
