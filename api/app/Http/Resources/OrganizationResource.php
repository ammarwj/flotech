<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Organization
 */
class OrganizationResource extends JsonResource
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
            'logo_url' => $this->logo_url,
            'banner_url' => $this->banner_url,
            'description' => $this->description,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'social_links' => $this->socialLinksMap(),
            'custom_domain' => $this->custom_domain,
            'owner_id' => $this->owner_id,
            'plan_id' => $this->plan_id,
            'plan_expires_at' => $this->plan_expires_at,
            'plan' => new PlanResource($this->whenLoaded('plan')),
        ];
    }
}
