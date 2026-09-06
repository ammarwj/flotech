<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An event as the super admin's cross-organization list sees it.
 *
 * Deliberately not EventResource: this list spans every organization, so it
 * needs the owning org on each row and none of the per-event configuration
 * (rules, courts, categories) the organizer dashboard reads.
 *
 * @mixin Event
 */
class AdminEventResource extends JsonResource
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
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
                'slug' => $this->organization->slug,
            ]),
            'plan' => new PlanSummaryResource($this->whenLoaded('plan')),
            'custom_domain' => $this->custom_domain,
            // Derived (see Event::domainStatus) — there is no status column to
            // drift from the certificate that is actually on disk.
            'domain_status' => $this->domainStatus(),
            'domain_verified_at' => $this->domain_verified_at,
            'domain_certified_at' => $this->domain_certified_at,
            // Shown verbatim in the admin UI: the certbot/DNS message is the
            // only clue why an activation did not take.
            'domain_error' => $this->domain_error,
            'domain_attempted_at' => $this->domain_attempted_at,
            'created_at' => $this->created_at,
        ];
    }
}
