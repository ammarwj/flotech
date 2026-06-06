<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    private function orgWithPlan(User $owner, array $features = []): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);
        foreach ($features as $key => $value) {
            $plan->features()->create(['feature_key' => $key, 'value' => $value]);
        }

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jakarta Cup 2026',
            'sport_type' => 'football',
            'tournament_format' => 'league',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-10',
            'registration_fee' => 1500000,
        ], $overrides);
    }

    public function test_member_can_create_event(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['max_active_events' => '5']);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.slug', 'jakarta-cup-2026')
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('events', ['organization_id' => $org->id, 'name' => 'Jakarta Cup 2026']);
    }

    public function test_plan_limit_blocks_extra_events(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['max_active_events' => '1']);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->assertCreated();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload(['name' => 'Second']))
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'max_active_events');
    }

    public function test_non_member_cannot_create_event(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner, ['max_active_events' => '5']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->assertStatus(403);
    }

    public function test_member_can_update_and_publish_event(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['max_active_events' => '5']);

        $eventId = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->json('data.id');

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$eventId}", ['name' => 'Renamed Cup'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Cup');

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$eventId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }
}
