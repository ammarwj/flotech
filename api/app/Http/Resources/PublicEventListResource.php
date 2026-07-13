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
            'tournament_format' => $this->tournament_format,
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'location_name' => $this->location_name,
            'banner_url' => $this->banner_url,
            'registration_fee' => (float) $this->registration_fee,
            'registration_is_open' => $this->isRegistrationOpen(),
            'max_teams' => $this->max_teams,
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
