<?php

namespace App\Http\Requests\Admin;

use App\Support\LandingMetrics;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body shape: { "metrics": [{ "metric_key": "tickets", "label": null,
 *               "is_active": true, "sort_order": 50 }, …] }
 *
 * `null` on any of the three settable columns means "inherit the catalog", which
 * is why they are `nullable` rather than `required` — see the migration for why
 * that matters beyond convenience.
 *
 * The catalog decides which keys exist, the same invariant `plan_features`
 * carries against `feature_definitions` (see CLAUDE.md): `metric_key` is a plain
 * string column, so a typo saves happily and is then read by nothing at all —
 * the metric simply never appears and there is no error anywhere to find.
 */
class UpdateLandingStatsRequest extends FormRequest
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
            // `present`, not `required`: an empty list is a legal no-op save.
            // The cap IS the catalog size — a second number would drift from it.
            'metrics' => ['present', 'array', 'max:'.count(LandingMetrics::keys())],
            // `distinct` is load-bearing: two entries for one key would
            // updateOrCreate twice and silently keep the last, under a green toast.
            'metrics.*.metric_key' => ['required', 'string', 'distinct', Rule::in(LandingMetrics::keys())],
            'metrics.*.label' => ['nullable', 'string', 'max:60'],
            'metrics.*.is_active' => ['nullable', 'boolean'],
            'metrics.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    /**
     * Rule::in on its own says "selected is invalid", which names neither the key
     * nor anything to do about it. Same spirit as SyncPlanFeaturesRequest.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $metrics = $this->input('metrics');

            if (! is_array($metrics)) {
                return;
            }

            $known = LandingMetrics::keys();

            foreach ($metrics as $index => $metric) {
                $key = is_array($metric) ? (string) ($metric['metric_key'] ?? '') : '';

                if ($key === '' || in_array($key, $known, true)) {
                    continue;
                }

                $suggestion = $this->closestTo($key, $known);

                $validator->errors()->add("metrics.{$index}.metric_key", $suggestion === null
                    ? "Metrik \"{$key}\" tidak ada di katalog counter landing."
                    : "Metrik \"{$key}\" tidak ada di katalog counter landing. Maksud Anda \"{$suggestion}\"?");
            }
        }];
    }

    /**
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
