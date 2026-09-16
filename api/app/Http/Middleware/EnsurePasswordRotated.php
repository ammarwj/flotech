<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses an account still holding a password somebody else chose for it.
 *
 * The officiating invite mails `welcomefloevent1` in its body. That is only a
 * bounded deviation from this codebase's stated posture ("sampaikan password
 * barunya lewat kanal yang aman", Admin\UserController::resetPassword) if the
 * default stops opening anything the moment it has been read — which has to be
 * decided here, not in the web app. A takeover screen is what the user sees; a
 * user who never loads the web app sees nothing at all.
 *
 * **Never register this globally.** It is stacked on duty surfaces only. Put it
 * on the whole API and `auth/me` 403s too, which leaves the web shell with no
 * way to learn *why* it was refused and nothing to render — a lock with the key
 * sealed inside. The endpoints that resolve the situation (`auth/me`,
 * `auth/refresh`, `auth/password`, `auth/logout`) stay reachable by living
 * outside every group this is attached to, which is a structural allowlist
 * rather than a list somebody has to remember to extend.
 *
 * The narrow placement is sound because of who can carry the flag: only
 * accounts the provisioning *created*, which own no organization and manage no
 * team. If some future flow ever sets it on a real user, that reasoning breaks
 * and this has to widen with it.
 */
class EnsurePasswordRotated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth('api')->user()?->must_change_password) {
            return ApiResponse::error(
                'Ganti password dulu sebelum melanjutkan.',
                ['code' => ['must_change_password']],
                403,
            );
        }

        return $next($request);
    }
}
