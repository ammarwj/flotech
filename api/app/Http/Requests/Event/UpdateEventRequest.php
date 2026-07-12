<?php

namespace App\Http\Requests\Event;

use App\Services\Catalog;
use App\Support\HybridConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'sport_type' => ['sometimes', Rule::in(Catalog::sportSlugs())],
            'tournament_format' => ['sometimes', Rule::in(Catalog::keys('tournament_format'))],
            'status' => ['sometimes', Rule::in(['draft', 'open', 'registration_closed', 'ongoing', 'finished', 'cancelled'])],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after_or_equal:start_date'],
            'registration_open' => ['nullable', 'date'],
            'registration_close' => ['nullable', 'date'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'location_address' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'banner_url' => ['nullable', 'string'],
            'max_teams' => ['nullable', 'integer', 'min:2'],
            'registration_fee' => ['nullable', 'numeric', 'min:0'],
            ...HybridConfig::validationRules(),
        ];
    }
}
