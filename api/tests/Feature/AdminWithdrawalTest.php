<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The payout queue. Transfers happen by hand in a banking app; these endpoints
 * only record what the admin did, so proof of transfer is mandatory.
 */
class AdminWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'super_admin']);
    }

    /** An org with 200.000 available and one pending 100.000 payout request. */
    private function pendingWithdrawal(): array
    {
        $owner = User::factory()->create();
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);
        $org = Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
        Wallet::create(['organization_id' => $org->id, 'balance_available' => 200000, 'total_earned' => 200000]);
        $org->bankAccounts()->create([
            'bank_name' => 'BCA', 'account_number' => '1234567890',
            'account_holder' => 'Budi Santoso', 'is_primary' => true,
        ]);

        $id = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->assertCreated()
            ->json('data.id');

        return [$org, Withdrawal::findOrFail($id)];
    }

    public function test_non_super_admin_cannot_see_the_queue(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->getJson('/api/v1/admin/withdrawals')->assertStatus(403);
    }

    public function test_admin_sees_the_pending_queue_with_the_full_account_number(): void
    {
        [$org, $withdrawal] = $this->pendingWithdrawal();

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/admin/withdrawals?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.reference', $withdrawal->reference)
            ->assertJsonPath('data.0.organization_name', $org->name)
            ->assertJsonPath('data.0.account_number', '1234567890')
            ->assertJsonPath('data.0.total_debit', 105000);
    }

    public function test_completing_a_withdrawal_requires_proof(): void
    {
        [, $withdrawal] = $this->pendingWithdrawal();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/withdrawals/{$withdrawal->id}/complete", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('proof_url');
    }

    public function test_process_then_complete_settles_without_moving_money_again(): void
    {
        [$org, $withdrawal] = $this->pendingWithdrawal();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/withdrawals/{$withdrawal->id}/process")
            ->assertOk()
            ->assertJsonPath('data.status', 'processing');

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/withdrawals/{$withdrawal->id}/complete", [
                'proof_url' => 'https://cdn.example.com/bukti.jpg',
                'transfer_reference' => 'TRX-99',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // The debit happened at request time; completing must not debit again.
        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_available' => '95000.00',
            'total_withdrawn' => '100000.00',
        ]);
        $this->assertSame(1, $org->wallet->transactions()->count());
    }

    public function test_rejecting_returns_the_held_funds(): void
    {
        [$org, $withdrawal] = $this->pendingWithdrawal();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/withdrawals/{$withdrawal->id}/reject", [
                'admin_note' => 'Nama rekening tidak cocok.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_available' => '200000.00',
            'total_withdrawn' => '0.00',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'category' => 'withdrawal_reversal',
            'type' => 'credit',
            'amount' => '105000.00',
        ]);
    }

    public function test_rejecting_requires_a_reason(): void
    {
        [, $withdrawal] = $this->pendingWithdrawal();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/withdrawals/{$withdrawal->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_note');
    }

    public function test_a_completed_withdrawal_cannot_be_rejected(): void
    {
        [, $withdrawal] = $this->pendingWithdrawal();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/withdrawals/{$withdrawal->id}/complete", [
                'proof_url' => 'https://cdn.example.com/bukti.jpg',
            ])->assertOk();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/withdrawals/{$withdrawal->id}/reject", ['admin_note' => 'Berubah pikiran'])
            ->assertStatus(409);
    }

    /** The "one active request" index must not outlive the request it guarded. */
    public function test_a_new_withdrawal_is_possible_once_the_previous_one_is_done(): void
    {
        [$org, $withdrawal] = $this->pendingWithdrawal();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/withdrawals/{$withdrawal->id}/complete", [
                'proof_url' => 'https://cdn.example.com/bukti.jpg',
            ])->assertOk();

        // More income arrives, so the organizer withdraws again.
        $org->wallet->increment('balance_available', 100000);

        $this->actingAs($org->owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->assertCreated();

        $this->assertDatabaseCount('withdrawals', 2);
    }
}
