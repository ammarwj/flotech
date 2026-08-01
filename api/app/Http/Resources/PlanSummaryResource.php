<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The slim plan an event carries: just enough for the dashboard to gate on.
 *
 * Deliberately not PlanResource. That one also emits `feature_details` — every
 * FeatureDefinition joined against the plan's values, thirteen entries a row —
 * which the events list would repeat for twenty events to render nothing. The
 * dashboard only ever reads `plan.features[key]`; `feature_details` exists for
 * the pricing surfaces, and those load plans directly.
 *
 * @mixin Plan
 */
class PlanSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            // The same raw map PlanResource emits, so web/lib/plan.ts reads one
            // shape whether it got here from an event or from the catalogue.
            'features' => $this->whenLoaded(
                'features',
                fn () => $this->features->pluck('value', 'feature_key'),
            ),
        ];
    }
}
