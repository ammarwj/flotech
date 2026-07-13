<?php

namespace App\Http\Resources;

use App\Models\FeatureDefinition;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    /**
     * Memoized so a collection of plans costs one query, not one per plan.
     *
     * @var Collection<int, FeatureDefinition>|null
     */
    private static ?Collection $definitions = null;

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
            'yearly_discount_percent' => (float) $this->yearly_discount_percent,
            'is_active' => (bool) $this->is_active,
            'is_public' => (bool) $this->is_public,
            'sort_order' => (int) $this->sort_order,
            'features' => $this->whenLoaded('features', fn () => $this->features->mapWithKeys(
                fn ($f) => [$f->feature_key => $f->value],
            )),
            // Every known feature, not just this plan's: the ones it lacks come
            // back with a null value so the UI can strike them through.
            'feature_details' => $this->whenLoaded('features', function () {
                $values = $this->features->pluck('value', 'feature_key');

                return static::definitions()->map(function (FeatureDefinition $def) use ($values) {
                    $value = $values[$def->feature_key] ?? null;

                    return [
                        'key' => $def->feature_key,
                        'label' => $def->feature_label,
                        'group' => $def->feature_group,
                        'type' => $def->feature_type,
                        'description' => $def->description,
                        'value' => $value,
                        'included' => static::isIncluded($def->feature_type, $value),
                    ];
                })->values();
            }),
        ];
    }

    /**
     * @return Collection<int, FeatureDefinition>
     */
    private static function definitions(): Collection
    {
        return static::$definitions ??= FeatureDefinition::orderBy('sort_order')->get();
    }

    private static function isIncluded(string $type, ?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return match ($type) {
            'boolean' => $value === 'true',
            'numeric' => $value !== '0',
            default => $value !== '',
        };
    }
}
