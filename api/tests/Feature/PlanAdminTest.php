<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanAdminTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    public function test_super_admin_can_create_plan(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->postJson('/api/v1/admin/plans', [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'price_monthly' => 1500000,
                'price_yearly' => 15000000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'enterprise');

        $this->assertDatabaseHas('plans', ['slug' => 'enterprise']);
    }

    public function test_regular_user_cannot_access_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']), 'api')
            ->getJson('/api/v1/admin/plans')
            ->assertStatus(403);
    }

    public function test_super_admin_can_sync_plan_features(): void
    {
        $plan = Plan::create([
            'name' => 'Pro',
            'slug' => 'pro-test',
            'price_monthly' => 399000,
            'price_yearly' => 3830000,
        ]);

        $this->actingAs($this->superAdmin(), 'api')
            ->putJson("/api/v1/admin/plans/{$plan->id}/features", [
                'features' => [
                    'max_active_events' => '10',
                    'qr_tickets' => 'true',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.features.qr_tickets', 'true');

        $this->assertDatabaseHas('plan_features', [
            'plan_id' => $plan->id,
            'feature_key' => 'max_active_events',
            'value' => '10',
        ]);
    }

    public function test_public_can_list_plans(): void
    {
        Plan::create([
            'name' => 'Free',
            'slug' => 'free-pub',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
