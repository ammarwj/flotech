<?php

namespace App\Http\Requests\Concerns;

use App\Models\Organization;
use App\Support\TemplateFields;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;

/**
 * Refuses a template field key that no form anywhere asked.
 *
 * `fields.*.key` used to be `Rule::in(array_keys(config(...)))` on both the ID
 * card and the certificate side — a list fixed at deploy time. It is not fixed
 * any more: a template may also place any question the organizer asked on one
 * of their own events' registration forms, and that list is a database read. So
 * the check moved out of the rule array and into here, where the organization
 * is in hand.
 *
 * One trait rather than a copy per surface, which is the point: two hand-kept
 * versions of "which keys exist" would be two answers to the question the
 * editor's picker is already a third reader of, and the one that drifted would
 * either refuse a key the picker offers or accept one nothing can print.
 *
 * This is the plan_features lesson applied to a second column. The key is a
 * plain string, so a typo used to save green and then print nothing — and
 * unlike a missing feature row, nobody finds out until five hundred cards have
 * come off the printer with a blank where a school name should be.
 *
 * The union across the organization's events is the right WIDTH deliberately:
 * templates are org-scoped and reused season after season, so narrowing it to
 * the event open in the editor would reject a template that legitimately prints
 * a question from a different one of their events. What stays refused is the
 * only shape of this mistake that can never print anything anywhere.
 */
trait ChecksTemplateFieldKeys
{
    /** @param  'id_card'|'certificate'  $surface */
    protected function checkFieldKeys(Validator $validator, Request $request, string $surface): void
    {
        $fields = $request->input('fields');

        if (! is_array($fields)) {
            return;
        }

        /** @var Organization|null $org */
        $org = $request->attributes->get('organization');

        if (! $org instanceof Organization) {
            return; // tenant middleware did not run; nothing to check against
        }

        $known = TemplateFields::knownKeys($org, $surface);

        foreach ($fields as $i => $field) {
            $key = is_array($field) ? ($field['key'] ?? null) : null;

            if (! is_string($key) || $key === '' || in_array($key, $known, true)) {
                continue;
            }

            // Same suggestion machinery as SyncPlanFeaturesRequest, for the same
            // reason: pointing at the key they meant beats a wall of valid ones.
            $suggestion = $this->closestFieldKey($key, $known);

            $validator->errors()->add("fields.{$i}.key", $suggestion === null
                ? "Field \"{$key}\" tidak dikenal. Kalau ini pertanyaan formulir, tambahkan dulu di formulir pendaftaran event."
                : "Field \"{$key}\" tidak dikenal. Maksud Anda \"{$suggestion}\"?");
        }
    }

    /**
     * @param  list<string>  $known
     */
    private function closestFieldKey(string $key, array $known): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($known as $candidate) {
            $distance = levenshtein($key, $candidate);

            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $bestDistance <= max(2, (int) floor(strlen($key) / 4)) ? $best : null;
    }
}
