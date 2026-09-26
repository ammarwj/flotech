<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Contracts\Providers\JWT as JWTProvider;

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
        if (auth('api')->user()?->must_change_password && ! $this->isImpersonated($request)) {
            return ApiResponse::error(
                'Ganti password dulu sebelum melanjutkan.',
                ['code' => ['must_change_password']],
                403,
            );
        }

        return $next($request);
    }

    /**
     * A super admin acting as this account (`act_as`) is let through, and that is
     * not a hole in the lock — it is the lock read precisely.
     *
     * What this middleware exists to stop is **the mailed default password**
     * opening the duty surface once somebody other than the referee has read the
     * invite. An impersonation token was never obtained with that password: it
     * is minted by Admin\UserController::impersonate() for an account that
     * already holds every power on the platform, including resetting this user's
     * password outright. Refusing it protects nothing and costs the whole
     * support path — "login sebagai" a freshly invited referee would land the
     * admin on a change-password takeover for somebody else's credential, whose
     * only exit is a full logout.
     *
     * Read off the token rather than from a session flag on purpose: the claim
     * travels with the credential, so nothing here can be spoofed by a client.
     */
    private function isImpersonated(Request $request): bool
    {
        $token = $this->bearer($request);

        if ($token === null) {
            return false;
        }

        try {
            return (app(JWTProvider::class)->decode($token)['act_as'] ?? null) !== null;
        } catch (\Throwable) {
            // Unparseable or unverified. The guard above already authenticated,
            // so this only happens for a credential that never was a JWT —
            // `actingAs()` in tests, or any future guard state. No impersonation
            // claim means the lock applies as normal, the safe default.
            return false;
        }
    }

    /**
     * The raw JWT of **this** request, and deliberately not `auth('api')->payload()`.
     *
     * That reads the shared `Tymon\JWTAuth\Factory`, whose claims Collection
     * `make($resetClaims = false)` never empties — `addClaim()` just `put()`s on
     * top of whatever is already there. So `act_as`, once written by a *mint*
     * (AuthService::issueImpersonationToken sets it via `customClaims()`), stays
     * in that singleton and reappears in the decode of every later token in the
     * same process. In one worker that serves an impersonate() call and then the
     * referee's own request, this middleware would wave the mailed default
     * password straight through — the exact thing it exists to stop, failing
     * open and silently. Decoding the request's own token is the only read whose
     * answer cannot come from another request's claims.
     */
    private function bearer(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }
}
