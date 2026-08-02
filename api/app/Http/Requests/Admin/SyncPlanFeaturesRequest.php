<?php

namespace App\Http\Requests\Admin;

use App\Models\FeatureDefinition;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SyncPlanFeaturesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Body shape: { "features": { "max_categories": "4", "qr_tickets": "true" } }
     *
     * Values are `required` rather than `nullable`: a blank value used to be
     * stored as `''`, which reads as *included* for numeric keys
     * (`'' !== '0'`) while gating parses it as 0. Removing a feature means
     * leaving its key out of the payload, and only that.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `present`, not `required`: an empty map is how a plan is stripped
            // back to nothing, and `required` rejects `[]`.
            'features' => ['present', 'array'],
            'features.*' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * The catalog decides which keys exist.
     *
     * `plan_features` is keyed by a plain string, so a typo used to save
     * happily and then vanish: nothing reads `online_registratio`, and the
     * page renders `feature_definitions`, so the row is invisible there too.
     * Worse, this endpoint prunes keys missing from the payload — mistyping an
     * existing key *deletes* the real one, silently switching the feature off
     * for every event on the plan. So an unknown key is a 422, not a row.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $features = $this->input('features');

            if (! is_array($features)) {
                return;
            }

            /** @var array<int, string> $known */
            $known = FeatureDefinition::query()->pluck('feature_key')->all();

            foreach (array_keys($features) as $key) {
                $key = (string) $key;

                if (in_array($key, $known, true)) {
                    continue;
                }

                $suggestion = $this->closestTo($key, $known);

                $validator->errors()->add("features.{$key}", $suggestion === null
                    ? "Feature key \"{$key}\" tidak ada di katalog fitur. Tambahkan definisinya dulu di menu Definisi Fitur."
                    : "Feature key \"{$key}\" tidak ada di katalog fitur. Maksud Anda \"{$suggestion}\"?");
            }
        }];
    }

    /**
     * Nearest known key within a typo's distance, so the admin gets pointed at
     * the key they meant instead of at a wall of valid ones.
     *
     * @param  array<int, string>  $known
     */
    private function closestTo(string $key, array $known): ?string
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
