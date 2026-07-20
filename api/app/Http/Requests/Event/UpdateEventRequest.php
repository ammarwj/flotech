<?php

namespace App\Http\Requests\Event;

use App\Services\Catalog;
use DateTimeZone;
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
            // `status` is intentionally not here: transitions run through
            // EventController@updateStatus, which enforces Event::TRANSITIONS.
            // Accepting it on the form save would let a caller jump straight to
            // `finished` — and that pays the organizer out.
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after_or_equal:start_date'],
            'timezone' => ['sometimes', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'registration_open' => ['nullable', 'date'],
            'registration_close' => ['nullable', 'date'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'location_address' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'banner_url' => ['nullable', 'string'],
            // Categories are only touched when the client sends them; the
            // controller full-replaces the list when present.
            ...EventCategoryRules::make('sometimes'),
        ];
    }
}
