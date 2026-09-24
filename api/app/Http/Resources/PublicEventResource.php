<?php

namespace App\Http\Resources;

use App\Models\Event;
use App\Services\PaymentRails;
use App\Support\RegistrationForm;
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
        // Read once for the whole payload: the roster maps every player and
        // every official through it, and forEvent() would otherwise re-parse
        // the same JSON column for each one.
        $form = RegistrationForm::forEvent($this->resource);

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
            // The *shape* of the form, which the public register page has to
            // have to render itself. The answers given to it stay organizer-side
            // — see the roster below, trimmed for the same reason: an address or
            // an ID number is exactly the class of field this resource filters.
            'registration_form' => $form->toArray(),
            'location_name' => $this->location_name,
            'location_address' => $this->location_address,
            'description' => $this->description,
            'banner_url' => $this->banner_url,
            // Each category runs its own format at its own price.
            'categories' => EventCategoryResource::collection($this->whenLoaded('categories')),
            'tickets_on_sale' => $this->ticketCategories()->where('is_active', true)->exists(),
            // UI-only: whether the checkout/register flow must show the
            // channel picker before paying. The money decision is still made
            // exactly once, in destinationFor() when the order is written —
            // this never substitutes for it.
            'requires_payment_channel' => app(PaymentRails::class)->methodFor($this->resource) === 'gateway',
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
                // Which competition they entered, and — in a hybrid — which
                // group they were drawn into. Both are already published by the
                // schedule and the standings; carrying them here is what lets a
                // roster say what it is a roster *of*.
                'category' => $t->relationLoaded('category') && $t->category ? [
                    'id' => $t->category->id,
                    'name' => $t->category->name,
                    'slug' => $t->category->slug,
                    'participant_type' => $t->category->participant_type,
                ] : null,
                'group_name' => $t->group_name,
                // Answers to the custom fields this event's organizer marked
                // public, already paired with their labels — see
                // RegistrationForm::publicAnswers(). Everything else they were
                // asked stays organizer-side.
                'custom_fields' => $form->publicAnswers('team', $t->custom_fields),
                // Roster is public, but only the on-pitch fields — no birth dates
                // or contact details.
                'players' => $t->relationLoaded('players') ? $t->players->map(fn ($p) => [
                    'id' => $p->id,
                    'full_name' => $p->full_name,
                    'jersey_number' => $p->jersey_number,
                    'position' => $p->position,
                    'photo_url' => $p->photo_url,
                    'custom_fields' => $form->publicAnswers('player', $p->custom_fields),
                ])->values() : null,
                // The bench is public for the same reason the roster is, and
                // holds nothing private either — name, role, photo.
                'officials' => $t->relationLoaded('officials') ? $t->officials->map(fn ($o) => [
                    'id' => $o->id,
                    'full_name' => $o->full_name,
                    'role' => $o->role,
                    'photo_url' => $o->photo_url,
                    'custom_fields' => $form->publicAnswers('official', $o->custom_fields),
                ])->values() : null,
            ])),
        ];
    }
}
