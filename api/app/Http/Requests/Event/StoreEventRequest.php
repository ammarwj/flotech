<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRequest extends FormRequest
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
            'slug' => ['nullable', 'string', 'max:100', 'alpha_dash'],
            'sport_type' => ['required', Rule::in(['football', 'futsal', 'badminton', 'padel', 'volleyball'])],
            'tournament_format' => ['required', Rule::in(['league', 'knockout_single', 'knockout_double', 'hybrid'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'registration_open' => ['nullable', 'date'],
            'registration_close' => ['nullable', 'date', 'after_or_equal:registration_open'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'location_address' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'banner_url' => ['nullable', 'string'],
            'max_teams' => ['nullable', 'integer', 'min:2'],
            'registration_fee' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
