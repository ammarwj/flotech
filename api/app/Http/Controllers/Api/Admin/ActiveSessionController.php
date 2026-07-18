<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveSessionResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Super-admin view of who is currently accessing the app.
 *
 * The list is the users who were active recently — `users.last_seen_at` within
 * RECENT_MINUTES, stamped on every authenticated request by TrackLastSeen. A
 * 30-day refresh token merely existing is NOT "accessing" (the access token is
 * cleared when the tab closes), so idle logins are deliberately excluded — they
 * would otherwise bury the handful of people actually online. Each row still
 * carries that user's active device sessions for IP / device detail.
 */
class ActiveSessionController extends Controller
{
    /** How far back "recently accessing" reaches. */
    private const RECENT_MINUTES = 30;

    public function index(): JsonResponse
    {
        // Active = a device session that is neither revoked nor expired.
        $active = fn ($q) => $q->whereNull('revoked_at')->where('expires_at', '>', now());

        $users = User::whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subMinutes(self::RECENT_MINUTES))
            ->with(['refreshTokens' => fn ($q) => $active($q)->latest('created_at')])
            ->orderByDesc('last_seen_at') // most recently active first
            ->get();

        return ApiResponse::success(ActiveSessionResource::collection($users));
    }
}
