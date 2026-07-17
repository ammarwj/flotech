<?php

namespace App\Http\Resources;

use App\Models\Organization;
use App\Services\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The organizer's own view of their organization. Not public — outward-facing
 * profiles use PublicOrganizationResource.
 *
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
            // Platform-wide, not a property of this organization — but the
            // dashboard is fetching this payload anyway and needs to know when
            // sales have been switched to manual transfer (warn the organizer,
            // surface the verification queue). Saves every dashboard page a
            // second round trip for one boolean.
            'payment_gateway_enabled' => PlatformSettings::paymentGatewayEnabled(),
            // One extra query per org. This resource only ever renders the orgs
            // the current user belongs to (realistically one), never a listing.
            'has_bank_account' => $this->bankAccounts()->where('is_primary', true)->exists(),
        ];
    }
}
