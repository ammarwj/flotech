<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The payout policy is super-admin editable and takes effect immediately, but
 * withdrawals already created keep the rules they were made under.
 */
class PlatformSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'super_admin']);
        PlatformSettings::flush();
    }

    /**
     * One setting out of the payload, by key.
     *
     * Looked up rather than indexed: the list is a catalog that grows, and
     * positional assertions here broke the moment `payment_gateway_enabled` was
     * added at the front of PlatformSettings::DEFINITIONS.
     *
     * @return array<string, mixed>
     */
    private function setting(array $payload, string $key): array
    {
        $found = collect($payload['settings'])->firstWhere('key', $key);
        $this->assertNotNull($found, "Setting {$key} tidak ada di payload.");

        return $found;
    }

    private function fundedOrg(User $owner, float $available): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);
        $org = Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
        Wallet::create([
            'organization_id' => $org->id,
            'balance_available' => $available,
            'total_earned' => $available,
        ]);
        $org->bankAccounts()->create([
            'bank_name' => 'BCA', 'account_number' => '1234567890',
            'account_holder' => 'Budi', 'is_primary' => true,
        ]);

        return $org;
    }

    public function test_settings_fall_back_to_config_defaults(): void
    {
        $payload = $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->json('data');

        $minimum = $this->setting($payload, 'wallet_minimum_withdrawal');
        $this->assertSame(100000, $minimum['value']);
        $this->assertFalse($minimum['is_overridden']);

        $this->assertSame(5000, $this->setting($payload, 'wallet_admin_fee')['value']);

        // The gateway switch defaults on, from config/payments.php.
        $this->assertTrue($this->setting($payload, 'payment_gateway_enabled')['value']);
    }

    public function test_non_super_admin_cannot_read_or_change_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->getJson('/api/v1/admin/settings')->assertStatus(403);
        $this->actingAs($user, 'api')
            ->putJson('/api/v1/admin/settings', ['wallet_admin_fee' => 0])
            ->assertStatus(403);
    }

    public function test_admin_can_change_the_payout_rules_and_they_take_effect(): void
    {
        $payload = $this->actingAs($this->admin, 'api')
            ->putJson('/api/v1/admin/settings', [
                'wallet_minimum_withdrawal' => 50000,
                'wallet_admin_fee' => 2500,
            ])
            ->assertOk()
            ->json('data');

        $minimum = $this->setting($payload, 'wallet_minimum_withdrawal');
        $this->assertSame(50000, $minimum['value']);
        $this->assertTrue($minimum['is_overridden']);

        $owner = User::factory()->create();
        $org = $this->fundedOrg($owner, 60000);

        // Would have failed under the old 100.000 minimum.
        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/wallet")
            ->assertOk()
            ->assertJsonPath('data.rules.minimum_withdrawal', 50000)
            ->assertJsonPath('data.rules.admin_fee', 2500);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 50000])
            ->assertCreated()
            ->assertJsonPath('data.admin_fee', 2500)
            ->assertJsonPath('data.total_debit', 52500);

        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_available' => '7500.00',
        ]);
    }

    /** Raising the fee later must not rewrite what a past payout charged. */
    public function test_changing_the_fee_does_not_rewrite_existing_withdrawals(): void
    {
        $owner = User::factory()->create();
        $org = $this->fundedOrg($owner, 300000);

        $id = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin, 'api')
            ->putJson('/api/v1/admin/settings', ['wallet_admin_fee' => 25000])
            ->assertOk();

        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/withdrawals")
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.admin_fee', 5000)      // the old fee, snapshotted
            ->assertJsonPath('data.0.total_debit', 105000);
    }

    public function test_absurd_values_are_rejected(): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson('/api/v1/admin/settings', ['wallet_admin_fee' => 5_000_000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('wallet_admin_fee');

        $this->actingAs($this->admin, 'api')
            ->putJson('/api/v1/admin/settings', ['wallet_minimum_withdrawal' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('wallet_minimum_withdrawal');

        $this->actingAs($this->admin, 'api')
            ->putJson('/api/v1/admin/settings', ['wallet_hold_days' => 365])
            ->assertStatus(422)
            ->assertJsonValidationErrors('wallet_hold_days');
    }
}
