<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends FormRequest
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
            // 'officiating' is accepted here but not on registration: nobody
            // signs up as crew, they are put there by an organizer — and the
            // provisioning writes the column directly. What this rule has to
            // allow is a referee who switched hats and comes back tomorrow.
            'default_mode' => ['required', Rule::in(['organizer', 'participant', 'officiating'])],
        ];
    }
}
