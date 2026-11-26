<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\EventOperator;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Two jobs, both about the same gap: a handful of operator-gated routes carry
 * no `{event}` in their path (`ticket-categories/{ticketCategory}`,
 * `rubbers/{rubber}`, `photos/{photo}`, …) because they already took their
 * shape from `tenant`, which only ever needed `organization`. An event
 * operator cannot be scoped through those the way `EventOrOperatorScope` does
 * for routes that DO carry `{event}`.
 *
 * - `resolveEventFrom()` walks a bound model's relations to find the `Event`
 *   it belongs to — directly (`->event`), through a match (`rubbers`), or
 *   through a category (`adjustments`). One place to add a relation chain
 *   when a new model-bound route needs one, instead of teaching every
 *   permission middleware its own traversal.
 * - `attribute()` is the scoping decision itself (org member → set
 *   `organization`; operator → set `operator`; neither → refuse), factored out
 *   of `EventOrOperatorScope` so `EnsureOperatorPermission` can run the exact
 *   same check for the model-bound routes that never pass through
 *   `event.access` at all. Two copies of this decision drifting apart is
 *   exactly the two-pembaca-`stage` shape CLAUDE.md keeps calling out.
 * - `resolveAccess()` is the entry point both middlewares actually call. It
 *   restores the one thing `attribute()` alone cannot see: whether the
 *   caller is a member of the `{organization}` named IN THE PATH. Before this
 *   feature existed, `tenant` checked exactly that, and the controller's own
 *   `$org->events()->findOrFail()` is what turned "this org's member named a
 *   foreign event" into 404 — never a 403. `attribute()` on its own checks
 *   membership against the EVENT's real organization, which is the right
 *   call for an operator (their identity is the event, the path org is
 *   noise) but the wrong one for an org member: it would turn a cross-org
 *   probe into a 403 the old stack never produced. `resolveAccess()` tries
 *   the path org first — found there means 404 for a foreign event, exactly
 *   as before — and only falls back to the event's own org (`attribute()`)
 *   for a caller who is not a member of the path org at all, which is
 *   exactly the shape an `EventOperator` has (no `organization_members` row
 *   anywhere).
 */
class EventOperatorAttribution
{
    public const ACCESS_OK = 'ok';

    public const ACCESS_NOT_FOUND = 'not_found';

    public const ACCESS_FORBIDDEN = 'forbidden';

    /**
     * The single door both `EventOrOperatorScope` and the model-bound branch
     * of `EnsureOperatorPermission` call. See the class docblock for why the
     * path organization is tried first.
     */
    public static function resolveAccess(Request $request, ?Event $event, ?User $user): string
    {
        if (! $event || ! $user) {
            return self::ACCESS_NOT_FOUND;
        }

        $routeOrg = self::routeOrganization($request);

        if ($routeOrg && self::isOrgMember($routeOrg, $user)) {
            // A member of the path's own organization — the event must be
            // one of ITS own rows, exactly what `$org->events()
            // ->findOrFail()` always enforced. A foreign event 404s here,
            // never a 403 that only this resolution layer would invent.
            if ($event->organization_id !== $routeOrg->id) {
                return self::ACCESS_NOT_FOUND;
            }

            $request->attributes->set('organization', $routeOrg);
            $request->attributes->set('event', $event);

            return self::ACCESS_OK;
        }

        // Not a member of the path org at all (or it does not exist) — the
        // only other legitimate caller is this event's own operator, whose
        // identity never depends on what organization id happened to sit in
        // the path. Falls back to the event's own organization for the
        // ordinary-member case other callers of `attribute()` already rely
        // on (DocumentsIndexScope, DocumentsBatchScope).
        return self::attribute($request, $event, $user)
            ? self::ACCESS_OK
            : self::ACCESS_FORBIDDEN;
    }

    protected static function routeOrganization(Request $request): ?Organization
    {
        $routeOrg = $request->route('organization');

        if ($routeOrg instanceof Organization) {
            return $routeOrg;
        }

        return is_string($routeOrg) && Str::isUuid($routeOrg) ? Organization::find($routeOrg) : null;
    }

    protected static function isOrgMember(Organization $org, User $user): bool
    {
        return $user->role === 'super_admin'
            || $org->owner_id === $user->id
            || $org->members()->where('user_id', $user->id)->exists();
    }

    /** Resolve the `Event` a bound route-model parameter belongs to. */
    public static function resolveEventFrom(Request $request, string $routeParam, string $modelClass): ?Event
    {
        $bound = $request->route($routeParam);

        $model = $bound instanceof Model
            ? $bound
            : (is_string($bound) ? $modelClass::find($bound) : null);

        return $model instanceof Model ? self::eventOf($model) : null;
    }

    protected static function eventOf(Model $model): ?Event
    {
        if ($model instanceof Event) {
            return $model;
        }

        if ($model instanceof GameMatch) {
            return $model->event;
        }

        if ($model instanceof EventCategory) {
            return $model->event;
        }

        // Direct `event()` relation — TicketCategory, RaceCategory, EventPhoto,
        // EventSponsor, Certificate, EventDiscountCode, …
        if (method_exists($model, 'event')) {
            $event = $model->event;

            if ($event instanceof Event) {
                return $event;
            }
        }

        // One hop through a match — MatchRubber.
        if (method_exists($model, 'match')) {
            $match = $model->match;

            if ($match instanceof GameMatch) {
                return $match->event;
            }
        }

        // One hop through a category — StandingAdjustment.
        if (method_exists($model, 'category')) {
            $category = $model->category;

            if ($category instanceof EventCategory) {
                return $category->event;
            }
        }

        return null;
    }

    /**
     * The scoping decision: is this user the event's org member, or one of
     * its event operators? Sets `organization`+`event`, or `event`+`operator`,
     * on the request and returns true; returns false (and sets nothing) for
     * anyone else.
     *
     * > **`organization` is never set for an operator** — see
     * > `EventOrOperatorScope`'s docblock for why that single fact is the
     * > entire security boundary.
     */
    public static function attribute(Request $request, Event $event, User $user): bool
    {
        $org = $event->organization;

        $isOrgMember = $org && (
            $user->role === 'super_admin'
            || $org->owner_id === $user->id
            || $org->members()->where('user_id', $user->id)->exists()
        );

        if ($isOrgMember) {
            $request->attributes->set('organization', $org);
            $request->attributes->set('event', $event);

            return true;
        }

        $operator = EventOperator::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $operator) {
            return false;
        }

        $request->attributes->set('event', $event);
        $request->attributes->set('operator', $operator);

        return true;
    }
}
