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
        Plan::create(['name' => 'Free', 'slug' => 'free-'.uniqid(), 'price' => 0]);
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

    /**
     * Organizers type profiles however they like. Compare all three shapes in
     * one request — asserting a single stored value would pass even if the
     * normalization in SocialPlatforms never ran.
     */
    public function test_organizer_social_handles_are_normalized_into_profile_urls(): void
    {
        $user = User::factory()->create();
        $org = Organization::create([
            'name' => 'Klub Ku',
            'slug' => 'klub-ku',
            'owner_id' => $user->id,
        ]);
        $org->members()->create(['user_id' => $user->id, 'role' => 'admin']);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}", [
                'social_links' => [
                    'instagram' => '@klubku',
                    'tiktok' => 'tiktok.com/@klubku',
                    'x' => 'https://x.com/klubku',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.social_links.instagram', 'https://instagram.com/klubku')
            ->assertJsonPath('data.social_links.tiktok', 'https://tiktok.com/@klubku')
            ->assertJsonPath('data.social_links.x', 'https://x.com/klubku')
            // Untouched platforms stay present as null so the settings form binds.
            ->assertJsonPath('data.social_links.facebook', null);
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

    /**
     * Without Midtrans credentials the Snap call is mocked and the order settles
     * on the spot — a dev convenience, not a payment.
     *
     * What settling produces is a *credit*, not an entitlement: nothing is
     * written to the organization, because the event the plan applies to does
     * not exist yet. Creating one is what spends it.
     */
    public function test_checkout_settles_into_an_unspent_credit_when_midtrans_not_configured(): void
    {
        $pro = Plan::create(['name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'price' => 399000]);
        $user = User::factory()->create();
        $org = Organization::create([
            'name' => 'Org Pay',
            'slug' => 'org-pay',
            'owner_id' => $user->id,
        ]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/checkout", [
                'plan_id' => $pro->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.mock', true)
            ->assertJsonPath('data.plan_order.status', 'paid')
            ->assertJsonPath('data.plan_order.event_id', null);

        $this->assertDatabaseHas('event_plan_orders', [
            'organization_id' => $org->id,
            'plan_id' => $pro->id,
            'status' => 'paid',
            'event_id' => null,
        ]);
    }
}
