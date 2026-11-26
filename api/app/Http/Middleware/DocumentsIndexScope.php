<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Models\Organization;
use App\Support\ApiResponse;
use App\Support\EventOperatorAttribution;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * `event.perm.documents.index` — for the three documents routes that carry
 * no `{event}` segment at all because they predate the operator feature:
 * `certificates` (index), `certificates/zip`, `id-cards/issued`. Each takes
 * an OPTIONAL `event_id` (query or body) rather than a route parameter, so
 * neither `EventOrOperatorScope` (requires `{event}` in the path) nor
 * `EnsureOperatorPermission`'s model-bound fallback (requires a bound
 * Eloquent row to walk) fits them.
 *
 * Membership on the ROUTE's `{organization}` is checked first, and wins
 * outright — exactly the priority `TenantScope` already had here before this
 * middleware existed. A member who names an `event_id` from a *different*
 * organization must still get the controller's own 404 (`$org->events()
 * ->find()` never matching), not a 403 from this middleware resolving the
 * event globally and finding no operator row for it; the two read as
 * different failures ("that event isn't yours" vs "you have no access at
 * all") and only the controller's query is allowed to decide which one this
 * is. Event-id-based operator resolution is the FALLBACK for a caller who is
 * not a member of the route org at all — an `EventOperator` never is, since
 * their whole identity is one event and no `organization_members` row.
 *
 * - Route-org member → set `organization`, pass straight through. Same as
 *   `tenant` always did; `event_id` scoping (and its 404 for a foreign
 *   event) stays entirely the controller's own job.
 * - Not a member, `event_id` given → resolve via
 *   {@see EventOperatorAttribution::attribute()}, same decision `event.access`
 *   makes, then require the `documents` category. An operator naming an
 *   event they do not hold 403s here, same as `event.access`.
 * - Not a member, no `event_id` → "browse the whole org's history", which
 *   only a real `organization_members` row can do — refused.
 *
 * `zip()`/`index()` in the controllers already assume this shape (an
 * operator with `event_id` unset or mismatched is rejected there too) — this
 * middleware is what makes the `operator` attribute they read ever get set.
 */
class DocumentsIndexScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();
        $org = $this->resolveOrganization($request);

        if (! $org) {
            return ApiResponse::error('Organisasi tidak ditemukan.', null, 404);
        }

        $isMember = $user->role === 'super_admin'
            || $org->owner_id === $user->id
            || $org->members()->where('user_id', $user->id)->exists();

        if ($isMember) {
            $request->attributes->set('organization', $org);

            return $next($request);
        }

        $eventId = $request->input('event_id');

        if (is_string($eventId) && Str::isUuid($eventId)) {
            $event = Event::with('organization')->find($eventId);

            if (! $event) {
                return ApiResponse::error('Event tidak ditemukan.', null, 404);
            }

            if (! EventOperatorAttribution::attribute($request, $event, $user)) {
                return ApiResponse::error('Kamu tidak punya akses ke event ini.', null, 403);
            }

            $operator = $request->attributes->get('operator');

            if ($operator && ! $operator->has('documents')) {
                return ApiResponse::error('Kamu tidak punya izin untuk aksi ini.', null, 403);
            }

            return $next($request);
        }

        return ApiResponse::error('Kamu bukan anggota organisasi ini.', null, 403);
    }

    protected function resolveOrganization(Request $request): ?Organization
    {
        $routeOrg = $request->route('organization');

        if ($routeOrg instanceof Organization) {
            return $routeOrg;
        }

        return is_string($routeOrg) && Str::isUuid($routeOrg) ? Organization::find($routeOrg) : null;
    }
}
