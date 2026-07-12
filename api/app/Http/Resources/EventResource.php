<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventResource extends JsonResource
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
            'slug' => $this->slug,
            'sport_type' => $this->sport_type,
            'tournament_format' => $this->tournament_format,
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'registration_open' => $this->registration_open,
            'registration_close' => $this->registration_close,
            'location_name' => $this->location_name,
            'location_address' => $this->location_address,
            'description' => $this->description,
            'banner_url' => $this->banner_url,
            'max_teams' => $this->max_teams,
            'registration_fee' => (float) $this->registration_fee,
            'bracket_config' => $this->bracket_config,
            'teams_count' => $this->whenCounted('teams'),
            'created_at' => $this->created_at,
        ];
    }
}
