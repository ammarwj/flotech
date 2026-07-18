<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-facing event payload for the landing page (no private fields).
 *
 * @mixin Event
 */
class PublicEventResource extends JsonResource
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
            // Kickoff times are UTC on the wire; this is the zone they mean.
            'timezone' => $this->timezone,
            'registration_open' => $this->registration_open,
            'registration_close' => $this->registration_close,
            'registration_is_open' => $this->isRegistrationOpen(),
            'location_name' => $this->location_name,
            'location_address' => $this->location_address,
            'description' => $this->description,
            'banner_url' => $this->banner_url,
            // Each category runs its own format at its own price.
            'categories' => EventCategoryResource::collection($this->whenLoaded('categories')),
            'tickets_on_sale' => $this->ticketCategories()->where('is_active', true)->exists(),
            'organization' => [
                'name' => $this->organization?->name,
                'slug' => $this->organization?->slug,
                'logo_url' => $this->organization?->logo_url,
            ],
            'sponsors' => $this->whenLoaded('sponsors', fn () => $this->sponsors->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'logo_url' => $s->logo_url,
                'website_url' => $s->website_url,
                'tier' => $s->tier,
            ])),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($p) => [
                'id' => $p->id,
                'album' => $p->album,
                'photo_url' => $p->photo_url,
                'caption' => $p->caption,
            ])),
            'approved_teams_count' => $this->teams()->where('status', 'approved')->count(),
            'approved_teams' => $this->whenLoaded('teams', fn () => $this->teams->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'logo_url' => $t->logo_url,
                // Roster is public, but only the on-pitch fields — no birth dates
                // or contact details.
                'players' => $t->relationLoaded('players') ? $t->players->map(fn ($p) => [
                    'id' => $p->id,
                    'full_name' => $p->full_name,
                    'jersey_number' => $p->jersey_number,
                    'position' => $p->position,
                    'photo_url' => $p->photo_url,
                ])->values() : null,
            ])),
        ];
    }
}
