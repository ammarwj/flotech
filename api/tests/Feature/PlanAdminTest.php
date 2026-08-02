<?php

namespace Tests\Feature;

use App\Models\FeatureDefinition;
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
                'price' => 1500000,
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
            'price' => 399000,
        ]);

        $this->actingAs($this->superAdmin(), 'api')
            ->putJson("/api/v1/admin/plans/{$plan->id}/features", [
                'features' => [
                    'max_categories' => '10',
                    'qr_tickets' => 'true',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.features.qr_tickets', 'true');

        $this->assertDatabaseHas('plan_features', [
            'plan_id' => $plan->id,
            'feature_key' => 'max_categories',
            'value' => '10',
        ]);
    }

    /**
     * A mistyped key is not a new feature — and because this endpoint prunes
     * whatever the payload leaves out, accepting one would delete the real key
     * in the same request. Compare against the plan's surviving value, not just
     * the status code: asserting 422 alone still passes if the prune ran first.
     */
    public function test_sync_rejects_a_feature_key_the_catalogue_does_not_define(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro-typo', 'price' => 350000]);
        $plan->features()->create(['feature_key' => 'online_registration', 'value' => 'true']);

        $this->actingAs($this->superAdmin(), 'api')
            ->putJson("/api/v1/admin/plans/{$plan->id}/features", [
                'features' => ['online_registratio' => 'true'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('features.online_registratio')
            // The nearest known key is offered, so the typo is findable.
            ->assertJsonFragment(['features.online_registratio' => [
                'Feature key "online_registratio" tidak ada di katalog fitur. Maksud Anda "online_registration"?',
            ]]);

        $this->assertDatabaseHas('plan_features', [
            'plan_id' => $plan->id,
            'feature_key' => 'online_registration',
            'value' => 'true',
        ]);
        $this->assertDatabaseMissing('plan_features', ['feature_key' => 'online_registratio']);
    }

    public function test_sync_rejects_a_blank_value_instead_of_storing_an_empty_string(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro-blank', 'price' => 350000]);

        $this->actingAs($this->superAdmin(), 'api')
            ->putJson("/api/v1/admin/plans/{$plan->id}/features", [
                'features' => ['max_categories' => ''],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('features.max_categories');
    }

    public function test_sync_accepts_an_empty_map_to_strip_a_plan(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro-strip', 'price' => 350000]);
        $plan->features()->create(['feature_key' => 'qr_tickets', 'value' => 'true']);

        $this->actingAs($this->superAdmin(), 'api')
            ->putJson("/api/v1/admin/plans/{$plan->id}/features", ['features' => []])
            ->assertOk();

        $this->assertDatabaseMissing('plan_features', ['plan_id' => $plan->id]);
    }

    public function test_feature_definition_key_must_look_like_a_key(): void
    {
        $this->actingAs($this->superAdmin(), 'api')
            ->postJson('/api/v1/admin/feature-definitions', [
                'feature_key' => 'Online Registration',
                'feature_label' => 'Pendaftaran online',
                'feature_type' => 'boolean',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('feature_key');
    }

    public function test_feature_definition_key_in_use_cannot_be_renamed_or_deleted(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro-def', 'price' => 350000]);
        $plan->features()->create(['feature_key' => 'qr_tickets', 'value' => 'true']);

        $def = FeatureDefinition::where('feature_key', 'qr_tickets')->firstOrFail();
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'api')
            ->putJson("/api/v1/admin/feature-definitions/{$def->id}", [
                'feature_key' => 'qr_ticket',
                'feature_label' => $def->feature_label,
                'feature_type' => $def->feature_type,
            ])
            ->assertStatus(422);

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/admin/feature-definitions/{$def->id}")
            ->assertStatus(422);

        // The label is still free to change — only the key is load-bearing.
        $this->actingAs($admin, 'api')
            ->putJson("/api/v1/admin/feature-definitions/{$def->id}", [
                'feature_key' => 'qr_tickets',
                'feature_label' => 'Tiket QR',
                'feature_type' => $def->feature_type,
            ])
            ->assertOk();

        $this->assertDatabaseHas('feature_definitions', [
            'id' => $def->id,
            'feature_key' => 'qr_tickets',
            'feature_label' => 'Tiket QR',
        ]);
    }

    public function test_public_can_list_plans(): void
    {
        Plan::create([
            'name' => 'Free',
            'slug' => 'free-pub',
            'price' => 0,
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
