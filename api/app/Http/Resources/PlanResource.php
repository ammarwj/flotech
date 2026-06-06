<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
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
            'description' => $this->description,
            'price_monthly' => (float) $this->price_monthly,
            'price_yearly' => (float) $this->price_yearly,
            'is_active' => (bool) $this->is_active,
            'is_public' => (bool) $this->is_public,
            'sort_order' => (int) $this->sort_order,
            'features' => $this->whenLoaded('features', fn () => $this->features->mapWithKeys(
                fn ($f) => [$f->feature_key => $f->value],
            )),
        ];
    }
}
