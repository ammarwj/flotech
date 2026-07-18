<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamp `users.last_seen_at` on authenticated requests so the admin "Sesi Aktif"
 * view knows who is currently accessing the app. Sits after `auth:api`, so the
 * user is already resolved (401s short-circuit before this runs).
 *
 * The write is throttled to at most once per minute per user, and saved quietly,
 * so it stays a single cheap UPDATE and never fires model events on every request.
 */
class TrackLastSeen
{
    /** Don't rewrite last_seen more often than this. */
    private const THROTTLE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();

        if ($user && ($user->last_seen_at === null
            || $user->last_seen_at->lt(now()->subSeconds(self::THROTTLE_SECONDS)))) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
