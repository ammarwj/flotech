<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\EventOperatorAttribution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows a route to one of {@see \App\Models\EventOperator}'s four
 * permission categories. No-op when the `operator` attribute is null — that
 * means the caller is an org member who already passed `EventOrOperatorScope`
 * (or `tenant`) with full access, and this middleware has nothing to add.
 * When `operator` IS set and lacks the category, 403.
 *
 * Stacked AFTER `EventOrOperatorScope` on routes carrying `{event}`, which is
 * what puts `operator` (or not) on the request. Routes bound to a model
 * instead of `{event}` (`ticket-categories/{ticketCategory}`, `rubbers/{rubber}`,
 * …) have no earlier middleware to do that resolution, so each subclass is
 * given the bound route parameter's name and model class and resolves the
 * event + attribution itself via `EventOperatorAttribution`.
 *
 * `org.admin` is never touched by this family. Every endpoint that moves
 * money, buys a plan, creates an event or confirms a match result stays
 * exactly where it already was — an operator never reaches `org.admin`
 * because `organization` is never set for one (see
 * `EventOrOperatorScope`'s docblock).
 */
abstract class EnsureOperatorPermission
{
    /** One of EventOperator::PERMISSIONS. */
    abstract protected function category(): string;

    public function handle(Request $request, Closure $next, ?string $routeParam = null, ?string $modelClass = null): Response
    {
        $operator = $request->attributes->get('operator');

        if ($operator === null) {
            // Route carries no `{event}` — EventOrOperatorScope never ran.
            // Resolve the event from the bound model ourselves.
            if ($routeParam !== null && $modelClass !== null) {
                $event = EventOperatorAttribution::resolveEventFrom($request, $routeParam, $modelClass);
                $user = auth('api')->user();

                // `resolveAccess()` is what keeps a bound model belonging to
                // another organization a 404 — exactly what plain Eloquent
                // route-model-binding + a `whereHas('event', …)` scope always
                // gave — rather than a 403 this middleware alone would
                // invent. See its docblock in EventOperatorAttribution.
                $access = EventOperatorAttribution::resolveAccess($request, $event, $user);

                if ($access === EventOperatorAttribution::ACCESS_NOT_FOUND) {
                    return ApiResponse::error('Event tidak ditemukan.', null, 404);
                }

                if ($access === EventOperatorAttribution::ACCESS_FORBIDDEN) {
                    return ApiResponse::error('Kamu tidak punya akses ke event ini.', null, 403);
                }

                $operator = $request->attributes->get('operator');
            }
        }

        // Org member (or nothing further to resolve) → unrestricted.
        if ($operator === null) {
            return $next($request);
        }

        if (! $operator->has($this->category())) {
            return ApiResponse::error('Kamu tidak punya izin untuk aksi ini.', null, 403);
        }

        return $next($request);
    }
}
