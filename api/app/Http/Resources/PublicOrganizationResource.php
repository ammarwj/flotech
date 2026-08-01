<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public organizer profile — the outward-facing subset of OrganizationResource
 * (no owner, no plan, no billing).
 *
 * Two shapes from one row, like BankAccountResource / PublicBankAccountResource:
 * `$rich` carries the profile an organizer paid for, and without it only the
 * identity needed to render the event grid survives. The keys are always
 * present either way so the frontend binds one shape.
 *
 * @mixin Organization
 */
class PublicOrganizationResource extends JsonResource
{
    /**
     * @param  Organization  $resource
     */
    public function __construct($resource, private bool $rich = false)
    {
        parent::__construct($resource);
    }

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
            'published_events_count' => (int) $this->published_events_count,

            'has_profile' => $this->rich,

            'banner_url' => $this->rich ? $this->banner_url : null,
            'description' => $this->rich ? $this->description : null,
            'contact_email' => $this->rich ? $this->contact_email : null,
            'contact_phone' => $this->rich ? $this->contact_phone : null,
            // Only the profiles the organizer actually filled in — the public
            // page renders one icon per entry, with nothing to skip. Cast to an
            // object so an organizer with no links serializes as {} and not [].
            'social_links' => (object) ($this->rich ? array_filter($this->socialLinksMap()) : []),
        ];
    }
}
