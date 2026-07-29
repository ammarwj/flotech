<?php

namespace App\Http\Requests\Event;

use App\Services\Catalog;
use App\Support\DisciplineRules;
use DateTimeZone;
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
            'sport_type' => ['required', Rule::in(Catalog::sportSlugs())],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            // The venue's zone; kickoff times typed by the organizer mean this
            // zone. Absent = the column default (Asia/Jakarta).
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'registration_open' => ['nullable', 'date'],
            'registration_close' => ['nullable', 'date', 'after_or_equal:registration_open'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'location_address' => ['nullable', 'string'],
            'courts' => ['nullable', 'array', 'max:50'],
            'courts.*' => ['string', 'max:255'],
            'description' => ['nullable', 'string'],
            'banner_url' => ['nullable', 'string'],
            // Competition rules, namespaced because this column will get
            // neighbours. A cleared field arrives as null and means "follow the
            // sport default" — DisciplineRules::clean() is what honours that.
            'rules_config' => ['sometimes', 'nullable', 'array'],
            'rules_config.discipline' => ['sometimes', 'nullable', 'array'],
            ...DisciplineRules::validationRules('rules_config.discipline.'),
            // Format, bracket config, fee and team cap live on each category.
            ...EventCategoryRules::make('required'),
        ];
    }
}
