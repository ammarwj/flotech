<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A logged-in user with their active device sessions, for the admin "Sesi Aktif"
 * page. Expects the `refreshTokens` relation to be eager-loaded already, filtered
 * to the active (non-revoked, non-expired) sessions.
 *
 * @mixin User
 */
class ActiveSessionResource extends JsonResource
{
    /** A user counts as "online" if seen within this many minutes. */
    private const ONLINE_MINUTES = 5;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // last_seen_at is per-user, not per-device: JWT access tokens are stateless,
        // so there is no per-token last-used timestamp. The Online badge therefore
        // reflects the user, not one specific session.
        $online = $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(self::ONLINE_MINUTES));

        // Collapse token churn into real devices: rotation and repeated logins
        // (without logout) leave many active tokens for the same browser/IP. Keep
        // the most recent token per (ip, device) so the list reads as devices, not
        // raw sessions.
        $devices = $this->refreshTokens
            ->sortByDesc('created_at')
            ->unique(fn ($token) => $token->ip_address.'|'.$token->device_info)
            ->values();

        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'role' => $this->role,
            'avatar_url' => $this->avatar_url,
            'online' => $online,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'session_count' => $devices->count(),
            'sessions' => $devices->map(fn ($token) => [
                'id' => $token->id,
                'device_info' => $token->device_info,   // raw user-agent
                'ip_address' => $token->ip_address,
                'login_at' => $token->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
