<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdatePreferencesRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class AuthController extends Controller
{
    public const REFRESH_COOKIE = 'refresh_token';

    public function __construct(protected AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'full_name' => $request->string('full_name'),
            'email' => $request->string('email'),
            'phone' => $request->input('phone'),
            'password' => $request->string('password'),
            'role' => 'user',
            // Which dashboard they asked for on the form. Someone who came to join
            // an event must not be dropped into onboarding, which builds an *org*.
            'default_mode' => $request->input('default_mode', 'organizer'),
        ]);

        $user->notify(new VerifyEmailNotification);

        $tokens = $this->auth->issueTokens($user, $request);

        return $this->respondWithTokens($tokens, $user, 'Registrasi berhasil', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (! auth('api')->attempt($credentials)) {
            return ApiResponse::error('Email atau password salah.', null, 401);
        }

        /** @var User $user */
        $user = auth('api')->user();
        $tokens = $this->auth->issueTokens($user, $request);

        return $this->respondWithTokens($tokens, $user, 'Login berhasil');
    }

    public function refresh(Request $request): JsonResponse
    {
        $plain = $request->cookie(self::REFRESH_COOKIE);

        if (! $plain) {
            return ApiResponse::error('Refresh token tidak ditemukan.', null, 401);
        }

        $tokens = $this->auth->rotate($plain, $request);

        if (! $tokens) {
            return ApiResponse::error('Refresh token tidak valid.', null, 401)
                ->withCookie($this->forgetRefreshCookie());
        }

        /** @var User $user */
        $user = auth('api')->setToken($tokens['access_token'])->user();

        return $this->respondWithTokens($tokens, $user, 'Token diperbarui');
    }

    public function logout(Request $request): JsonResponse
    {
        if ($plain = $request->cookie(self::REFRESH_COOKIE)) {
            $this->auth->revoke($plain);
        }

        auth('api')->logout();

        return ApiResponse::success(null, 'Logout berhasil')
            ->withCookie($this->forgetRefreshCookie());
    }

    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        return ApiResponse::success(new UserResource($this->withAuth($user)));
    }

    /**
     * Loads what every auth response owes the shell before it can choose a
     * landing page.
     *
     * `personnelAssignments` is why this exists: a task account owns no
     * organization and manages no team, so without it the web app sees an
     * account with nothing in it and drops a referee into the organizer
     * dashboard — or worse, into onboarding, which is outside the shell that
     * carries the password gate. "Not loaded" and "has no assignments" are the
     * same absence, so it has to be loaded on every response the client learns
     * its identity from, not just on me().
     *
     * `loadExists` on the other three is the second half of the same question:
     * they are what `account_types` is derived from, and the shell asks that to
     * tell a crew-only account (plain "Area Petugas" label) from a referee who
     * also manages a team — they really do have two hats, and keep the mode
     * switcher. Existence, not rows: this response prints none of them, `teams`
     * is wide (payment snapshots, proof uploads) and one manager can hold many,
     * and loading them would also start publishing `managed_teams` &c. here,
     * which is the admin screen's payload, not the shell's. All three land in
     * one statement as subqueries, and `accountTypes()` reads either shape.
     *
     * Two extra queries, said out loud.
     */
    protected function withAuth(User $user): User
    {
        return $user
            ->load('personnelAssignments.event')
            ->loadExists(['ownedOrganizations', 'organizationMemberships', 'managedTeams']);
    }

    /**
     * Remembers which dashboard hat the user wears, so the next login lands
     * there. The web app writes this every time the mode switcher is used.
     */
    public function updatePreferences(UpdatePreferencesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        $user->update(['default_mode' => $request->string('default_mode')]);

        return ApiResponse::success(new UserResource($user), 'Preferensi disimpan');
    }

    /**
     * Change the signed-in user's own password.
     *
     * Every other device is signed out (their refresh tokens are revoked), the
     * caller's own session is spared — a password change that also logged you
     * out of the tab you changed it in would look like a failure. The one that
     * survives is identified by the refresh cookie on this very request, so
     * "spare the current device" can't be spoofed by a body field.
     */
    public function updatePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        $user->forceFill([
            'password' => $request->string('password'),
            // Any "remember me" cookie was minted against the old password.
            'remember_token' => Str::random(60),
            // Clears the officiating invite's forced rotation. No separate
            // endpoint for it: the rule that makes the rotation real is
            // ChangePasswordRequest's `different:current_password`, which only
            // holds on this path. A "set my first password" route would have to
            // drop `current_password` and with it that rule, leaving the
            // mailed default re-settable.
            'must_change_password' => false,
        ])->save();

        $others = $this->auth->revokeAllFor($user, $request->cookie(self::REFRESH_COOKIE));

        return ApiResponse::success(
            ['revoked_sessions' => $others],
            $others > 0
                ? "Password diperbarui. {$others} sesi di perangkat lain telah dikeluarkan."
                : 'Password berhasil diperbarui.',
        );
    }

    /**
     * @param  array{access_token: string, refresh_token: string, expires_in: int}  $tokens
     */
    protected function respondWithTokens(array $tokens, User $user, string $message, int $status = 200): JsonResponse
    {
        return ApiResponse::success([
            'access_token' => $tokens['access_token'],
            'token_type' => 'bearer',
            'expires_in' => $tokens['expires_in'],
            'user' => new UserResource($this->withAuth($user)),
        ], $message, $status)->withCookie($this->makeRefreshCookie($tokens['refresh_token']));
    }

    protected function makeRefreshCookie(string $value): Cookie
    {
        return cookie(
            name: self::REFRESH_COOKIE,
            value: $value,
            minutes: AuthService::REFRESH_TTL_DAYS * 24 * 60,
            path: '/',
            domain: config('session.domain'),
            secure: app()->environment('production'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    protected function forgetRefreshCookie(): Cookie
    {
        return cookie()->forget(self::REFRESH_COOKIE, '/', config('session.domain'));
    }
}
