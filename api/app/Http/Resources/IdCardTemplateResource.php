<?php

namespace App\Http\Resources;

use App\Models\IdCardTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IdCardTemplate
 */
class IdCardTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'background_url' => $this->background_url,
            // Floats, per the model's cast. The editor divides by these to get
            // its canvas scale, and a string here would silently make every
            // preview slightly wrong rather than throw.
            'width_mm' => $this->width_mm,
            'height_mm' => $this->height_mm,
            'fields' => $this->fields ?? [],
            'created_at' => $this->created_at,
        ];
    }
}
