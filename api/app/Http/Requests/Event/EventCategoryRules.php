<?php

namespace App\Http\Requests\Event;

use App\Models\MatchRubber;
use App\Models\Sport;
use App\Services\Catalog;
use App\Support\HybridConfig;
use Illuminate\Validation\Rule;

/**
 * Validation rules for the `categories[]` block shared by the create and update
 * event requests. Each category carries its own format, bracket config, fee and
 * team cap; the bracket-config sub-rules are the same catalog-driven ones the
 * event used to use, re-prefixed under `categories.*`.
 */
class EventCategoryRules
{
    /**
     * @param  string  $presence  'required' (create) or 'sometimes' (update)
     * @return array<string, mixed>
     */
    public static function make(string $presence): array
    {
        $rules = [
            'categories' => [$presence, 'array', 'min:1'],
            'categories.*.id' => ['nullable', 'uuid'],
            'categories.*.name' => ['required', 'string', 'max:255'],
            'categories.*.slug' => ['nullable', 'string', 'max:100', 'alpha_dash'],
            'categories.*.tournament_format' => ['required', Rule::in(Catalog::keys('tournament_format'))],
            'categories.*.registration_fee' => ['nullable', 'numeric', 'min:0'],
            'categories.*.max_teams' => ['nullable', 'integer', 'min:2'],

            // Whether this shape is one the *sport* supports, and whether it may
            // still be changed, both need the event — checked in the controller.
            // Omitted means 'team', which is what a category has always been.
            'categories.*.participant_type' => ['nullable', Rule::in(Sport::MODES)],

            // The partai a squad-vs-squad tie is played over. Only meaningful for
            // a squad category on a racket sport; EventController drops it
            // elsewhere rather than storing a template nothing will ever read.
            'categories.*.rubber_format' => ['nullable', 'array', 'max:12'],
            'categories.*.rubber_format.*.label' => ['required', 'string', 'max:60'],
            'categories.*.rubber_format.*.type' => ['required', Rule::in(array_keys(MatchRubber::TYPES))],
        ];

        // HybridConfig::validationRules() keys everything under `bracket_config`;
        // re-home them under each category.
        foreach (HybridConfig::validationRules() as $key => $ruleSet) {
            $rules['categories.*.'.$key] = $ruleSet;
        }

        return $rules;
    }
}
