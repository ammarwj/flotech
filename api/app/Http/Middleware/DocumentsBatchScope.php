<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Models\Organization;
use App\Support\ApiResponse;
use App\Support\EventOperatorAttribution;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * `event.perm.documents.batch` — for the six batch-status/download routes
 * that carry a `{batch}` segment instead of `{event}`
 * (`certificate-batches/{batch}[/download]`, `id-card-batches/{batch}[/download]`,
 * `bib-batches/{batch}[/download]`). A batch id is a key into a Cache entry
 * written by the queue job that built it, not an Eloquent row — there is
 * nothing for `EnsureOperatorPermission`'s model-bound fallback to walk, and
 * no `{event}` for `EventOrOperatorScope` to resolve.
 *
 * The cache payload itself already carries `event_id` (and, for an
 * org-wide certificate zip, `organization_id`) — written by the job at
 * enqueue time, read here the same way each controller's own
 * `ensureOwned()`/`ensureOwnedBatch()` already reads it for the ownership
 * check. This middleware runs that same lookup earlier, purely to populate
 * the `operator`/`organization` request attributes those methods (and the
 * `documents` category) depend on — it does not replace either controller's
 * own ownership check, which still runs and still 404s a foreign batch.
 *
 * The cache key is read through the job's own `cacheKey()` static — never
 * reconstructed from a prefix string here — so there is exactly one place
 * that knows the format each job writes under.
 *
 * - Payload has an `event_id` → resolve via
 *   {@see EventOperatorAttribution::attribute()}, same decision `event.access`
 *   makes, then require the `documents` category for an operator.
 * - No `event_id` (an org-wide certificate zip, `event_id: null`) → only an
 *   org member can have asked for that in the first place (see
 *   `CertificateController::zip()` — `event_id` is `required` for an
 *   operator), so this falls back to plain membership on `organization_id`.
 * - Missing/expired batch → let the request through; the controller's own
 *   404 for "batch not found or expired" is the one that should fire, not a
 *   generic one here that would leak whether the id ever existed.
 */
class DocumentsBatchScope
{
    /** @param class-string $jobClass One of the three batch jobs, for its `cacheKey()`. */
    public function handle(Request $request, Closure $next, string $jobClass): Response
    {
        $user = auth('api')->user();
        $batch = $request->route('batch');
        $batch = is_string($batch) ? $batch : null;

        $payload = $batch ? Cache::get($jobClass::cacheKey($batch)) : null;

        if (! is_array($payload)) {
            return $next($request);
        }

        $eventId = $payload['event_id'] ?? null;

        if ($eventId) {
            $event = Event::with('organization')->find($eventId);

            if ($event && EventOperatorAttribution::attribute($request, $event, $user)) {
                $operator = $request->attributes->get('operator');

                if ($operator && ! $operator->has('documents')) {
                    return ApiResponse::error('Kamu tidak punya izin untuk aksi ini.', null, 403);
                }

                return $next($request);
            }
        }

        $orgId = $payload['organization_id'] ?? null;
        $org = $orgId ? Organization::find($orgId) : null;

        if ($org) {
            $isMember = $user->role === 'super_admin'
                || $org->owner_id === $user->id
                || $org->members()->where('user_id', $user->id)->exists();

            if ($isMember) {
                $request->attributes->set('organization', $org);
            }
        }

        // Anyone else (including a mismatched operator) falls through with
        // no attribute set — the controller's own ownership check then 404s,
        // same as it always has for a foreign batch.
        return $next($request);
    }
}
