<?php

namespace App\Http\Requests\Race\Concerns;

use App\Models\RaceCategory;

/**
 * The email-domain rules, shared by the store and update requests for the same
 * reason ValidatesRacePrices is: one door accepting a domain the other refuses
 * means an organizer can create a condition they can never edit again. The
 * precedent is PayoutDestinationRequest, where the two copies would have let
 * one door take a phone number the other rejected.
 *
 * Normalisation runs HERE, before validation, so `distinct` and the pattern
 * both see the stored form: `@UB.AC.ID` and ` ub.ac.id ` are one value, not
 * two that pass `distinct` and then collapse into a duplicate row on save.
 * RaceCategory::normalizeDomain() is the single speller, read again by
 * acceptsEmail() on the way out.
 */
trait ValidatesEmailDomains
{
    /**
     * @param  'nullable'|'sometimes'  $presence
     * @return array<string, mixed>
     */
    protected function domainRules(string $presence): array
    {
        return [
            // `sometimes` on update is what lets an organizer rename a category
            // without resending its conditions — an absent key leaves the
            // stored list alone, and `[]` clears it. Same guard, same reason as
            // `prices` beside it and `officials` on the registration form.
            'email_domains' => [$presence, 'array', 'max:20'],
            // No `distinct`: normalizeDomains() below already folds the three
            // spellings of one domain into one entry, so a `distinct` here
            // would be a branch nothing can reach — and the version that could
            // reach it (refusing instead of folding) would 422 an organizer for
            // typing `@ub.ac.id` after `ub.ac.id`, which is the same condition
            // said twice, not a mistake.
            'email_domains.*' => [
                'required',
                'string',
                'max:253',
                // Dot-separated labels only: letters, digits and inner hyphens,
                // with a final label of at least two letters. This is what
                // refuses an address ("nama@ub.ac.id" survives normalisation,
                // but "nama ub" does not), a bare word with no dot, and the
                // wildcards an organizer may reach for — `*.ub.ac.id` would
                // read as a suffix rule, and matching here is exact.
                'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/',
            ],
        ];
    }

    /**
     * Normalise every entry and drop the ones that normalise to nothing.
     *
     * Dropping rather than refusing: a trailing blank chip is the organizer's
     * input widget, not a value they meant, and 422 on something they cannot
     * see on screen is worse than silence. A chip that normalises to a
     * non-domain still reaches the regex above and is named there.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalizeDomains(array $input): array
    {
        if (! is_array($input['email_domains'] ?? null)) {
            return $input;
        }

        $clean = [];

        foreach ($input['email_domains'] as $raw) {
            if (! is_string($raw)) {
                // Left as-is so the string rule above names it, rather than
                // vanishing into a silently shorter list.
                $clean[] = $raw;

                continue;
            }

            $domain = RaceCategory::normalizeDomain($raw);

            if ($domain !== null) {
                $clean[] = $domain;
            }
        }

        $input['email_domains'] = $this->dedupeStrings($clean);

        return $input;
    }

    /**
     * Drop repeats while leaving non-strings untouched for the rules to name.
     *
     * array_unique() would cast them, and a nested array cast to string is a
     * warning rather than the validation message the organizer needs.
     *
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private function dedupeStrings(array $values): array
    {
        $out = [];
        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                $out[] = $value;

                continue;
            }

            if (isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $out[] = $value;
        }

        return $out;
    }
}
