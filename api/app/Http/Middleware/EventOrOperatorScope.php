<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Support\ApiResponse;
use App\Support\EventOperatorAttribution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A parallel path to `tenant`, for the handful of event-scoped routes an
 * {@see EventOperator} may also reach. Only used on sub-routes carrying an
 * `{event}` route parameter — there is no org-less equivalent of
 * `organizations/{organization}` for an operator, because an operator's whole
 * identity is "this one event".
 *
 * Resolves two kinds of caller:
 *
 * - An org member who passes the same membership check `TenantScope` runs →
 *   sets the `organization` attribute exactly like `tenant` does, and is
 *   subject to no further restriction here. This is what lets an owner/admin
 *   keep using every route this middleware guards without their access ever
 *   narrowing.
 * - An `EventOperator` (a user with a row in `event_operators` for this
 *   event, and no `organization_members` row at all) → sets `event` and
 *   `operator` attributes.
 *
 * > **Invariant: `organization` is NEVER set for an event operator.** This is
 * > the whole reason `EventOperator` is its own table rather than a cheaper
 * > trick like widening `organization_members`. The moment `organization` is
 * > on the request, EVERY controller written against `tenant` runs for its
 * > holder without knowing anything changed — wallet, billing, plan-orders,
 * > bank accounts, event deletion, all of it. `EnsureOrgAdmin` cannot save
 * * us here either: it reads `organization`, so if that attribute existed for
 * > an operator, `org.admin` routes would open too. The only thing standing
 * > between an operator and the whole organizer API is this attribute never
 * > existing for them, not a second gate remembering to check a role. See
 * > `EnsureOperatorPermission` for the other half (narrowing WHICH routes an
 * > operator may use at all), and `EventPersonnelScope` for the identical
 * > reasoning already written for referees/staff.
 */
class EventOrOperatorScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();
        $eventId = $request->route('event');
        $eventId = is_string($eventId) ? $eventId : ($eventId->id ?? null);

        $event = $eventId ? Event::with('organization')->find($eventId) : null;

        // `resolveAccess()` is what tells "this org's member named an event
        // belonging to a different org" (404 — exactly the old `tenant` +
        // `$org->events()->findOrFail()` behaviour) apart from "resolved to
        // this event's own org or operator, but not this caller" (403). See
        // its docblock in EventOperatorAttribution.
        return match (EventOperatorAttribution::resolveAccess($request, $event, $user)) {
            EventOperatorAttribution::ACCESS_OK => $next($request),
            EventOperatorAttribution::ACCESS_NOT_FOUND => ApiResponse::error('Event tidak ditemukan.', null, 404),
            // One message for "not a member" and "not an operator", same
            // reasoning as EventPersonnelScope: the difference is not the
            // caller's to learn.
            default => ApiResponse::error('Kamu tidak punya akses ke event ini.', null, 403),
        };
    }
}
