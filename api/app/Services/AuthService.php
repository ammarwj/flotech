<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserRefreshToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthService
{
    /**
     * Refresh-token lifetime in days (PRD §8.4: 30 days).
     */
    public const REFRESH_TTL_DAYS = 30;

    /**
     * Issue a fresh access token (RS256) + a new rotating refresh token.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function issueTokens(User $user, Request $request): array
    {
        $accessToken = JWTAuth::fromUser($user);

        $plainRefresh = Str::random(64);

        UserRefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => $this->hash($plainRefresh),
            'device_info' => Str::limit((string) $request->userAgent(), 1000, ''),
            'ip_address' => $request->ip(),
            'expires_at' => Carbon::now()->addDays(self::REFRESH_TTL_DAYS),
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $plainRefresh,
            'expires_in' => (int) config('jwt.ttl') * 60,
        ];
    }

    /**
     * Issue an access token that acts as $target, marked as an impersonation
     * session opened by $admin (claim `act_as`).
     *
     * Deliberately mints ONLY an access token: no UserRefreshToken row and no
     * refresh cookie. The admin's own refresh cookie is left untouched, which is
     * what makes "kembali ke admin" possible without re-login — the frontend just
     * drops this token and refreshes from the admin's still-valid cookie. It also
     * means the impersonation dies on its own (tab close, token expiry) instead
     * of becoming a 30-day session for someone else's account.
     */
    public function issueImpersonationToken(User $target, User $admin): string
    {
        return JWTAuth::customClaims(['act_as' => $admin->id])->fromUser($target);
    }

    /**
     * Validate a refresh token, rotate it (single-use), and issue a new pair.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
     */
    public function rotate(string $plainRefresh, Request $request): ?array
    {
        $record = UserRefreshToken::where('token_hash', $this->hash($plainRefresh))->first();

        if (! $record || ! $record->isActive()) {
            return null;
        }

        $record->forceFill(['revoked_at' => Carbon::now()])->save();

        $user = $record->user;
        if (! $user) {
            return null;
        }

        return $this->issueTokens($user, $request);
    }

    /**
     * Revoke a single refresh token (logout on this device).
     */
    public function revoke(string $plainRefresh): void
    {
        UserRefreshToken::where('token_hash', $this->hash($plainRefresh))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }

    protected function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
