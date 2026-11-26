<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Event;
use App\Models\Organization;
use Illuminate\Http\Request;

/**
 * Lets a controller resolve its event the same way whether the caller is an
 * org member (via `tenant`/`EventOrOperatorScope`, `organization` attribute
 * set) or an event operator (via `EventOrOperatorScope`/`EnsureOperatorPermission`,
 * only `event` + `operator` set, `organization` deliberately never set — see
 * `EventOrOperatorScope`'s docblock for why that absence is the whole point).
 *
 * `scopedEvent()` prefers the `event` attribute middleware already resolved
 * and verified — both `tenant`+org.admin flows and the operator flow run
 * through `EventOrOperatorScope` or `EnsureOperatorPermission`, both of which
 * set it — and only falls back to the old `organization->events()->find()`
 * path for the handful of controllers this trait is dropped into without
 * their route carrying `event.access`.
 *
 * `org()` stays nullable on purpose: reaching for it unconditionally is
 * exactly the mistake `EventOrOperatorScope` exists to make impossible. A
 * controller that genuinely needs the organization (not just the event) is
 * a sign the route belongs behind `org.admin`, not this trait.
 */
trait ResolvesEventScope
{
    /**
     * The `organization` attribute when it is set (an org member). For an
     * operator it never is — see `EventOrOperatorScope`'s docblock — so this
     * falls back to the resolved event's own `organization` relation. That
     * fallback is read-only convenience (e.g. `$org->certificateTemplates()`
     * to generate against an existing template), never a door to anything
     * org-wide an operator should not reach: the controller methods that use
     * it are all already behind an `event.perm.*` category, scoped to the
     * one event the operator was granted.
     */
    protected function org(Request $request): ?Organization
    {
        $org = $request->attributes->get('organization');

        if ($org instanceof Organization) {
            return $org;
        }

        $event = $request->attributes->get('event');

        return $event instanceof Event ? $event->organization : null;
    }

    protected function scopedEvent(Request $request, string $eventId): Event
    {
        $event = $request->attributes->get('event');

        if ($event instanceof Event && $event->id === $eventId) {
            return $event;
        }

        $org = $this->org($request);

        abort_unless($org !== null, 404, 'Event tidak ditemukan.');

        return $org->events()->findOrFail($eventId);
    }
}
