<?php

namespace App\Http\Middleware;

use App\Services\DomainService;
use Closure;
use Illuminate\Http\Middleware\HandleCors;

/**
 * Adds live custom domains to the CORS allowlist at request time.
 *
 * config/cors.php can only name FRONTEND_URL, but a page served from eventa.id
 * calls api.floevent.id — so without this every fetch from a custom domain is
 * blocked by the browser and the page renders empty with no server-side error to
 * find. The list has to be dynamic because domains are added by an admin at
 * runtime, not at deploy time.
 *
 * Safe despite `supports_credentials: true`: the refresh cookie is bound to
 * .floevent.id (AuthController::makeRefreshCookie), so the browser structurally
 * cannot send it from a custom domain — and the only origins added here are ones
 * a super admin registered and we already serve.
 */
class DynamicCors extends HandleCors
{
    public function handle($request, Closure $next)
    {
        $origin = $request->headers->get('Origin');

        // Mutating the config is enough: the parent reads cors.* out of the
        // container's config on every request, so there is no built service to
        // refresh here.
        if ($origin && array_key_exists(DomainService::normalize($origin), DomainService::active())) {
            // Push the Origin verbatim, not a rebuilt URL — the header is echoed
            // back as-is and a scheme or port mismatch fails the browser's check.
            config(['cors.allowed_origins' => array_values(array_unique(
                [...config('cors.allowed_origins', []), $origin],
            ))]);
        }

        return parent::handle($request, $next);
    }
}
