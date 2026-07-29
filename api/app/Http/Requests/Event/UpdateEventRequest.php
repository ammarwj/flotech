<?php

namespace App\Http\Requests\Event;

use App\Services\Catalog;
use App\Support\DisciplineRules;
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
            'courts' => ['nullable', 'array', 'max:50'],
            'courts.*' => ['string', 'max:255'],
            'description' => ['nullable', 'string'],
            'banner_url' => ['nullable', 'string'],
            // Same shape as the store request. The controller merges this into
            // the stored column per namespace rather than replacing it, so a
            // form that only knows about `discipline` can't wipe its neighbours.
            'rules_config' => ['sometimes', 'nullable', 'array'],
            'rules_config.discipline' => ['sometimes', 'nullable', 'array'],
            ...DisciplineRules::validationRules('rules_config.discipline.'),
            // Categories are only touched when the client sends them; the
            // controller full-replaces the list when present.
            ...EventCategoryRules::make('sometimes'),
        ];
    }
}
