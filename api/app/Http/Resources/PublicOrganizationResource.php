<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public organizer profile — the outward-facing subset of OrganizationResource
 * (no owner, no plan, no billing).
 *
 * @mixin Organization
 */
class PublicOrganizationResource extends JsonResource
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
            // Only the profiles the organizer actually filled in — the public
            // page renders one icon per entry, with nothing to skip. Cast to an
            // object so an organizer with no links serializes as {} and not [].
            'social_links' => (object) array_filter($this->socialLinksMap()),
            'published_events_count' => (int) $this->published_events_count,
        ];
    }
}
