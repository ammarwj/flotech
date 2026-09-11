<?php

namespace App\Http\Resources;

use App\Models\Event;
use App\Services\PaymentRails;
use App\Support\RegistrationForm;
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
            // The plan this event runs on — every entitlement the dashboard
            // gates on is read from here. Only present when eager-loaded;
            // `plan_id` is always there so the client can tell "no plan" from
            // "not loaded".
            'plan_id' => $this->plan_id,
            'plan' => new PlanSummaryResource($this->whenLoaded('plan')),
            // The rail this event picked, and the rail it will actually sell on.
            // Two fields because the difference is the point: the first binds the
            // form, the second is what the next buyer meets — they part while the
            // platform gateway is switched off. Published rather than left for
            // the client to recombine, same reasoning as `next_statuses`.
            'payment_method' => $this->payment_method,
            'effective_payment_method' => app(PaymentRails::class)->methodFor($this->resource),
            'name' => $this->name,
            'slug' => $this->slug,
            'sport_type' => $this->sport_type,
            // The sport itself, so the client doesn't have to look it up.
            'sport' => $this->sportDefinition(),
            'status' => $this->status,
            // Where this event may go next, so the dashboard renders exactly the
            // moves the API will accept instead of keeping its own copy of the
            // transition table. Empty = terminal.
            'next_statuses' => $this->nextStatuses(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            // Kickoff times are UTC on the wire; this is the zone they mean.
            'timezone' => $this->timezone,
            'registration_open' => $this->registration_open,
            'registration_close' => $this->registration_close,
            'location_name' => $this->location_name,
            'location_address' => $this->location_address,
            // Named courts for scheduling; [] when the organizer hasn't set any.
            'courts' => $this->courts ?? [],
            // Competition rules the organizer set for this event. Cast so an
            // event that has never been configured binds as {} rather than [].
            // Organizer-only: the public resource has no business carrying it.
            'rules_config' => (object) ($this->rules_config ?? []),
            // What the registration form asks entrants for. Unlike rules_config
            // this one is also public — the public register page renders itself
            // from it — which is why it is its own column and not a namespace
            // inside that one.
            'registration_form' => RegistrationForm::forEvent($this->resource)->toArray(),
            'description' => $this->description,
            'banner_url' => $this->banner_url,
            // Format, bracket config, fee and team cap live on each category.
            'categories' => EventCategoryResource::collection($this->whenLoaded('categories')),
            'teams_count' => $this->whenCounted('teams'),
            'created_at' => $this->created_at,
        ];
    }
}
