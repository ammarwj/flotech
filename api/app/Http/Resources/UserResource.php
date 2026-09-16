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
            // Always published, never whenLoaded: this is the flag the shell's
            // password gate reads, and me() is its only source after a reload.
            // Absent once = the gate renders nothing and the lock is invisible
            // until the next API call 403s with no screen to explain it.
            'must_change_password' => (bool) $this->must_change_password,
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
            'managed_teams' => $this->whenLoaded('managedTeams', fn () => $this->managedTeams->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'event_name' => $t->event?->name,
            ])->values()),
            // Events this account is crew on. Unlike the three above, every
            // auth response eager-loads this one (see AuthController::withAuth)
            // — it decides the landing page, and a task account owns no
            // organization, so without it a referee lands on an empty organizer
            // dashboard. Still whenLoaded, because the admin user list pages
            // through hundreds of rows and has no use for it.
            'officiating' => $this->whenLoaded('personnelAssignments', fn () => $this->personnelAssignments
                ->filter(fn ($p) => $p->event !== null)
                ->map(fn ($p) => [
                    'event_id' => $p->event_id,
                    'event_name' => $p->event->name,
                    'kind' => $p->kind,
                ])->values()),
            // Diturunkan dari ketiga relasi di atas — butuh ketiganya disiapkan,
            // karena "tidak dimuat" tidak bisa dibedakan dari "tidak punya" dan
            // akan melabeli setiap organizer sebagai akun kosong. Gerbangnya di
            // model, bukan di sini: respons auth menyiapkannya lewat
            // loadExists() (cuma butuh ada/tidak), layar admin lewat load().
            'account_types' => $this->when(
                $this->hasAccountContext(),
                fn () => $this->accountTypes(),
            ),
        ];
    }
}
