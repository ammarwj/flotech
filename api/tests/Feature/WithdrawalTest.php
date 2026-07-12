<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    /** Put money straight in the available balance; income is covered elsewhere. */
    private function fund(Organization $org, float $available): Wallet
    {
        return Wallet::create([
            'organization_id' => $org->id,
            'balance_available' => $available,
            'total_earned' => $available,
        ]);
    }

    private function bank(Organization $org): void
    {
        $org->bankAccounts()->create([
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder' => 'Budi Santoso',
            'is_primary' => true,
        ]);
    }

    public function test_withdrawal_requires_a_bank_account(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->fund($org, 500000);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 200000])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tambahkan rekening bank terlebih dahulu.');
    }

    public function test_withdrawal_below_the_minimum_is_rejected(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->fund($org, 500000);
        $this->bank($org);

        // config default minimum is 100.000
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 50000])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount', 'Jumlah di bawah minimal penarikan.');

        $this->assertDatabaseCount('withdrawals', 0);
    }

    /** The admin fee comes out of the same balance, so it must be covered too. */
    public function test_balance_must_cover_the_amount_plus_the_admin_fee(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->fund($org, 100000); // exactly the amount, but 5.000 short of amount + fee
        $this->bank($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount', 'Saldo tidak mencukupi.');
    }

    public function test_pending_funds_cannot_be_withdrawn(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        Wallet::create(['organization_id' => $org->id, 'balance_pending' => 500000, 'balance_available' => 0]);
        $this->bank($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 200000])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount', 'Saldo tidak mencukupi.');
    }

    public function test_request_holds_the_funds_immediately(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->fund($org, 200000);
        $this->bank($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->assertCreated()
            ->assertJsonPath('data.amount', 100000)
            ->assertJsonPath('data.admin_fee', 5000)
            ->assertJsonPath('data.total_debit', 105000)
            ->assertJsonPath('data.status', 'pending');

        // 200.000 − (100.000 + 5.000) = 95.000 left available.
        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_available' => '95000.00',
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'organization_id' => $org->id,
            'type' => 'debit',
            'category' => 'withdrawal',
            'status' => 'available',
            'amount' => '105000.00',
        ]);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/wallet")
            ->assertOk()
            ->assertJsonPath('data.balance_on_hold', 105000)
            ->assertJsonPath('data.has_active_withdrawal', true);
    }

    public function test_only_one_active_withdrawal_at_a_time(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->fund($org, 500000);
        $this->bank($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->assertCreated();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Masih ada permintaan penarikan yang sedang diproses.');

        $this->assertDatabaseCount('withdrawals', 1);
    }

    public function test_organizer_can_cancel_a_pending_withdrawal_and_get_the_funds_back(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->fund($org, 200000);
        $this->bank($org);

        $id = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->json('data.id');

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/withdrawals/{$id}")
            ->assertOk();

        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_available' => '200000.00',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'category' => 'withdrawal_reversal',
            'type' => 'credit',
            'amount' => '105000.00',
        ]);
    }

    /**
     * The gate-scanning operator is a full tenant member, so `tenant` alone
     * would let them repoint the payout account.
     */
    public function test_operator_member_cannot_touch_the_wallet(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $org = $this->org($owner);
        $this->fund($org, 500000);
        $this->bank($org);

        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $this->actingAs($operator, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/wallet")
            ->assertStatus(403);

        $this->actingAs($operator, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/bank-accounts", [
                'bank_name' => 'BCA', 'account_number' => '999', 'account_holder' => 'Maling',
            ])
            ->assertStatus(403);

        $this->actingAs($operator, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 200000])
            ->assertStatus(403);
    }

    public function test_org_admin_member_can_use_the_wallet(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $org = $this->org($owner);
        $this->fund($org, 500000);
        $org->members()->create(['user_id' => $admin->id, 'role' => 'admin']);

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/wallet")
            ->assertOk()
            ->assertJsonPath('data.balance_available', 500000);
    }

    public function test_outsider_cannot_read_another_orgs_wallet(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $org = $this->org($owner);
        $this->fund($org, 500000);

        $this->actingAs($outsider, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/wallet")
            ->assertStatus(403);
    }

    public function test_adding_a_bank_account_demotes_the_previous_primary(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->bank($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/bank-accounts", [
                'bank_name' => 'Mandiri', 'account_number' => '9876543210', 'account_holder' => 'Budi Santoso',
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_primary', true)
            // The organizer only sees the tail of their own number.
            ->assertJsonPath('data.account_number', '******3210');

        $this->assertSame(1, $org->bankAccounts()->where('is_primary', true)->count());
    }
}
