<?php

namespace App\Http\Requests\Event;

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
        ];

        // HybridConfig::validationRules() keys everything under `bracket_config`;
        // re-home them under each category.
        foreach (HybridConfig::validationRules() as $key => $ruleSet) {
            $rules['categories.*.'.$key] = $ruleSet;
        }

        return $rules;
    }
}
