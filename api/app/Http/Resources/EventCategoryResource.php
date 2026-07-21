<?php

namespace App\Http\Resources;

use App\Models\EventCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventCategory
 */
class EventCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'participant_type' => $this->participant_type,
            'rubber_format' => $this->rubber_format,
            // Derived, not stored: needs the sport, which lives on the event.
            'uses_rubbers' => $this->usesRubbers(),
            'roster_size' => $this->rosterSize(),
            'tournament_format' => $this->tournament_format,
            'engine' => $this->engine(),
            'registration_fee' => (float) $this->registration_fee,
            'max_teams' => $this->max_teams,
            'bracket_config' => $this->bracket_config,
            'sort_order' => $this->sort_order,
            'teams_count' => $this->whenCounted('teams'),
        ];
    }
}
