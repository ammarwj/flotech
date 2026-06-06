<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        return ApiResponse::success(new UserResource(auth('api')->user()));
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
            'user' => new UserResource($user),
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
