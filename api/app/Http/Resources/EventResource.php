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
            // The sport itself, so the client doesn't have to look it up.
            'sport' => $this->sportDefinition(),
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'registration_open' => $this->registration_open,
            'registration_close' => $this->registration_close,
            'location_name' => $this->location_name,
            'location_address' => $this->location_address,
            'description' => $this->description,
            'banner_url' => $this->banner_url,
            // Format, bracket config, fee and team cap live on each category.
            'categories' => EventCategoryResource::collection($this->whenLoaded('categories')),
            'teams_count' => $this->whenCounted('teams'),
            'created_at' => $this->created_at,
        ];
    }
}
