<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrows a tenant route to the organization's owner or an `admin` member.
 *
 * TenantScope alone admits every member, including `operator` (the person
 * scanning tickets at the gate). That's fine for events, but not for money:
 * an operator must not be able to change the payout account, drain the wallet,
 * or buy a plan. Always stack this after `tenant`.
 */
class EnsureOrgAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Organization|null $org */
        $org = $request->attributes->get('organization');
        $user = auth('api')->user();

        if (! $org || ! $user) {
            return ApiResponse::error('Organisasi tidak ditemukan.', null, 404);
        }

        $allowed = $user->role === 'super_admin'
            || $org->owner_id === $user->id
            || $org->members()
                ->where('user_id', $user->id)
                ->where('role', 'admin')
                ->exists();

        if (! $allowed) {
            return ApiResponse::error('Hanya pemilik atau admin organisasi yang bisa mengakses halaman ini.', null, 403);
        }

        return $next($request);
    }
}
