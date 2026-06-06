<?php

namespace App\Http\Resources;

use App\Models\FeatureDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FeatureDefinition
 */
class FeatureDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'feature_key' => $this->feature_key,
            'feature_label' => $this->feature_label,
            'feature_group' => $this->feature_group,
            'feature_type' => $this->feature_type,
            'description' => $this->description,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
