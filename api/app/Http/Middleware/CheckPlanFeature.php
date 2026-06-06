<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\PlanGate;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route behind a boolean plan feature.
 * Usage: ->middleware('plan.feature:certificate_generator')
 * Requires TenantScope to have resolved the organization first.
 */
class CheckPlanFeature
{
    public function __construct(protected PlanGate $gate) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $org = $request->attributes->get('organization');

        if (! $org instanceof Organization) {
            return ApiResponse::error('Konteks organisasi tidak tersedia.', null, 400);
        }

        if (! $this->gate->allows($org, $feature)) {
            return ApiResponse::error(
                'Paket langgananmu tidak mencakup fitur ini.',
                ['feature' => $feature],
                403,
            );
        }

        return $next($request);
    }
}
