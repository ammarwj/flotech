<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_onboard_organization_with_free_plan(): void
    {
        Plan::create(['name' => 'Free', 'slug' => 'free', 'price_monthly' => 0, 'price_yearly' => 0]);
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/v1/organizations', ['name' => 'Jakarta Sports EO'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Jakarta Sports EO');

        $orgId = $response->json('data.id');
        $this->assertDatabaseHas('organizations', ['id' => $orgId, 'owner_id' => $user->id]);
        $this->assertDatabaseHas('organization_members', ['organization_id' => $orgId, 'user_id' => $user->id, 'role' => 'admin']);
    }

    public function test_member_can_view_organization(): void
    {
        $user = User::factory()->create();
        $org = Organization::create([
            'name' => 'My Org',
            'slug' => 'my-org',
            'owner_id' => $user->id,
        ]);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $org->id);
    }

    public function test_non_member_cannot_view_organization(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $org = Organization::create([
            'name' => 'Private Org',
            'slug' => 'private-org',
            'owner_id' => $owner->id,
        ]);

        $this->actingAs($stranger, 'api')
            ->getJson("/api/v1/organizations/{$org->id}")
            ->assertStatus(403);
    }

    public function test_checkout_activates_subscription_when_midtrans_not_configured(): void
    {
        $free = Plan::create(['name' => 'Free', 'slug' => 'free', 'price_monthly' => 0, 'price_yearly' => 0]);
        $pro = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'price_monthly' => 399000, 'price_yearly' => 3830000]);
        $user = User::factory()->create();
        $org = Organization::create([
            'name' => 'Org Pay',
            'slug' => 'org-pay',
            'owner_id' => $user->id,
            'plan_id' => $free->id,
        ]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/subscriptions/checkout", [
                'plan_id' => $pro->id,
                'billing_cycle' => 'monthly',
            ])
            ->assertCreated()
            ->assertJsonPath('data.mock', true)
            ->assertJsonPath('data.subscription.status', 'active');

        $this->assertDatabaseHas('organizations', ['id' => $org->id, 'plan_id' => $pro->id]);
    }
}
