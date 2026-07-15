<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Trimmed event payload for the public catalog listing.
 *
 * Unlike PublicEventResource, the team count and ticket flag are read from
 * withCount()/withExists() aggregates instead of querying per row.
 *
 * @mixin Event
 */
class PublicEventListResource extends JsonResource
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
            'sport_type' => $this->sport_type,
            'sport' => $this->sportDefinition(),
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'location_name' => $this->location_name,
            'banner_url' => $this->banner_url,
            // One event may run several categories at different prices; the card
            // shows the count and the fee range.
            'categories_count' => $this->whenLoaded('categories', fn () => $this->categories->count()),
            'registration_fee_min' => $this->whenLoaded('categories', fn () => (float) $this->categories->min('registration_fee')),
            'registration_fee_max' => $this->whenLoaded('categories', fn () => (float) $this->categories->max('registration_fee')),
            'registration_is_open' => $this->isRegistrationOpen(),
            'approved_teams_count' => (int) $this->approved_teams_count,
            'tickets_on_sale' => (bool) $this->tickets_on_sale,
            'organization' => [
                'name' => $this->organization?->name,
                'slug' => $this->organization?->slug,
                'logo_url' => $this->organization?->logo_url,
            ],
        ];
    }
}
