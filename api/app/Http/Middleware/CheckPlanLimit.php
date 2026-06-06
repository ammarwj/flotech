<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\PlanGate;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a numeric plan limit and exposes it to the controller.
 * Usage: ->middleware('plan.limit:max_active_events')
 *
 * Resource counts live in the feature controllers (added per phase), so this
 * middleware attaches `plan_limit` / `plan_limit_key` to the request and the
 * controller enforces the count via PlanGate::withinLimit(). Unlimited (-1)
 * limits short-circuit here.
 */
class CheckPlanLimit
{
    public function __construct(protected PlanGate $gate) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $org = $request->attributes->get('organization');

        if (! $org instanceof Organization) {
            return ApiResponse::error('Konteks organisasi tidak tersedia.', null, 400);
        }

        $limit = $this->gate->limit($org, $feature);

        if ($limit === null) {
            return ApiResponse::error(
                'Batas paket untuk fitur ini belum dikonfigurasi.',
                ['feature' => $feature],
                403,
            );
        }

        $request->attributes->set('plan_limit_key', $feature);
        $request->attributes->set('plan_limit', $limit);

        return $next($request);
    }
}
