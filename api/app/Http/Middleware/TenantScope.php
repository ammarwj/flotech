<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current organization (tenant) from the route binding or the
 * X-Organization-Id header and verifies the authenticated user belongs to it.
 * The resolved org is stored on the request as the "organization" attribute.
 */
class TenantScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $org = $this->resolveOrganization($request);

        if (! $org) {
            return ApiResponse::error('Organisasi tidak ditemukan.', null, 404);
        }

        $user = auth('api')->user();
        $isMember = $user->role === 'super_admin'
            || $org->owner_id === $user->id
            || $org->members()->where('user_id', $user->id)->exists();

        if (! $isMember) {
            return ApiResponse::error('Kamu bukan anggota organisasi ini.', null, 403);
        }

        $request->attributes->set('organization', $org);

        return $next($request);
    }

    protected function resolveOrganization(Request $request): ?Organization
    {
        $routeOrg = $request->route('organization');

        if ($routeOrg instanceof Organization) {
            return $routeOrg;
        }

        $id = is_string($routeOrg) && $routeOrg !== ''
            ? $routeOrg
            : $request->header('X-Organization-Id');

        return $id ? Organization::find($id) : null;
    }
}
