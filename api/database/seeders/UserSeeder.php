<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds one user per role with predictable credentials (password: "password").
 *
 * Roles covered:
 *  - users.role: super_admin | user
 *  - organization_members.role: admin | operator (via a demo organization)
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Platform/SaaS administrator.
        $this->makeUser('admin@flo-event.id', 'Super Admin', 'super_admin');

        // Organizer who owns the demo organization (org-level role: admin).
        $owner = $this->makeUser('owner@flo-event.id', 'Demo Owner', 'user');

        // Staff member of the demo organization (org-level role: operator).
        $operator = $this->makeUser('operator@flo-event.id', 'Demo Operator', 'user');

        // Participant (registers/manages teams; no organization membership).
        $this->makeUser('participant@flo-event.id', 'Demo Participant', 'user');

        // ---- Demo organization to give the org-level roles something real ----
        $planId = Plan::where('slug', 'free')->value('id') ?? Plan::value('id');

        $org = Organization::firstOrCreate(
            ['slug' => 'demo-organizer'],
            [
                'name' => 'Demo Organizer',
                'owner_id' => $owner->id,
                'contact_email' => $owner->email,
                'plan_id' => $planId,
            ],
        );

        OrganizationMember::firstOrCreate(
            ['organization_id' => $org->id, 'user_id' => $owner->id],
            ['role' => 'admin', 'invited_by' => $owner->id],
        );

        OrganizationMember::firstOrCreate(
            ['organization_id' => $org->id, 'user_id' => $operator->id],
            ['role' => 'operator', 'invited_by' => $owner->id],
        );

        $this->command?->info('Seeded users (password: "password"):');
        $this->command?->table(
            ['Email', 'User role', 'Org role'],
            [
                ['admin@flo-event.id', 'super_admin', '—'],
                ['owner@flo-event.id', 'user', 'admin (Demo Organizer)'],
                ['operator@flo-event.id', 'user', 'operator (Demo Organizer)'],
                ['participant@flo-event.id', 'user', '—'],
            ],
        );
    }

    /**
     * Create or refresh a user with a known password and verified email.
     */
    protected function makeUser(string $email, string $name, string $role): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'full_name' => $name,
                'role' => $role,
                'password' => Hash::make('password'),
                'is_verified' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
