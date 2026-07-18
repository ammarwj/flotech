<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,
            'role' => $this->role,
            'default_mode' => $this->default_mode,
            'is_verified' => (bool) $this->is_verified,
            'email_verified_at' => $this->email_verified_at,
            'last_seen_at' => $this->last_seen_at,
            // Org context — only present when the caller eager-loads them (admin
            // user management). Kept out of the default payload via whenLoaded so
            // auth me() and other callers stay a single query.
            'owned_organizations' => $this->whenLoaded('ownedOrganizations', fn () => $this->ownedOrganizations->map(fn ($org) => [
                'id' => $org->id,
                'name' => $org->name,
            ])->values()),
            'memberships' => $this->whenLoaded('organizationMemberships', fn () => $this->organizationMemberships->map(fn ($m) => [
                'organization_id' => $m->organization_id,
                'organization_name' => $m->organization?->name,
                'role' => $m->role,
            ])->values()),
        ];
    }
}
