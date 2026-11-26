<?php

namespace App\Http\Resources;

use App\Models\Event;
use App\Models\EventOperator;
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
            // The public URL's first segment, for the few authenticated pages
            // that have to reach a public endpoint — the participant's own
            // registration-fee payment previews a discount code against
            // `public/events/{orgSlug}/{eventSlug}/discount-preview`. Only when
            // eager-loaded, so no caller pays for a join it does not read.
            'organization_slug' => $this->whenLoaded('organization', fn () => $this->organization->slug),
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
            // For the "pay again" button on the participant dashboard: whether
            // that payment must go through the channel picker before a Snap
            // token is created. Same rule PublicEventResource uses for the
            // first-time checkout flow.
            'requires_payment_channel' => app(PaymentRails::class)->methodFor($this->resource) === 'gateway',
            'name' => $this->name,
            'slug' => $this->slug,
            // The event's own hostname, and how far it has got. The organizer's
            // domain page is the one screen that has to explain a pending DNS
            // record or a failed certificate, so the error string comes with it
            // — it is the only clue why activation did not take.
            //
            // `domain_status` is derived (Event::domainStatus): there is no
            // column, because whether a domain is live is a fact about a
            // certificate on disk. `subdomain` beside the hostname is what says
            // we issued the name rather than the organizer bringing it.
            'custom_domain' => $this->custom_domain,
            'subdomain' => $this->subdomain,
            'domain_status' => $this->domainStatus(),
            'domain_error' => $this->domain_error,
            // The A record a `own`-kind domain has to point at. Config, not a
            // secret — it is in the public DNS of every domain already live —
            // and published here so the instructions on that page cannot name a
            // different IP than verifyDns() checks against.
            'domain_server_ip' => (string) config('domains.server_ip'),
            // Unlisted from /event, no platform footer. One method answers it
            // (Event::isWhiteLabel), the same one PublicEventResource publishes.
            'white_label' => $this->isWhiteLabel(),
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
            // Whether the door is open right now: status `open` AND inside the
            // window. Published rather than left for the client to recombine,
            // same reasoning as `next_statuses` and `effective_payment_method` —
            // two readers of one rule drift, and the drift is invisible. It is
            // what freezes the participant's own team page once registration
            // closes, so it has to be the same boolean MyTeamController checks.
            'registration_is_open' => $this->isRegistrationOpen(),
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
            // null = unrestricted (an org member — owner/admin/org-level
            // operator), the exact meaning `web/lib/event-operator-permissions.ts`
            // already gives an absent restriction. Only non-null for an event
            // OPERATOR viewing their own event, and read off the `operator`
            // request attribute `EventOrOperatorScope`/`EnsureOperatorPermission`
            // set for THIS caller on THIS request — never a second query, and
            // never a cached/shared value another viewer's resource call could
            // leak into this one's response.
            'viewer_permissions' => $this->viewerPermissions($request),
        ];
    }

    /**
     * @return array{can_ticketing: bool, can_scheduling: bool, can_registration: bool, can_documents: bool}|null
     */
    protected function viewerPermissions(Request $request): ?array
    {
        $operator = $request->attributes->get('operator');

        if (! $operator instanceof EventOperator) {
            return null;
        }

        return [
            'can_ticketing' => $operator->can_ticketing,
            'can_scheduling' => $operator->can_scheduling,
            'can_registration' => $operator->can_registration,
            'can_documents' => $operator->can_documents,
        ];
    }
}
