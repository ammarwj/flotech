<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\TicketOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Refunds reverse the organizer's credit. While the money is still held the
 * credit is cancelled outright; once released, it becomes a debit — which may
 * push the balance negative if the organizer already withdrew it.
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'super_admin']);
    }

    /** @return array{0: Organization, 1: Event, 2: TicketOrder} */
    private function paidOrder(): array
    {
        $owner = User::factory()->create();
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);
        $plan->features()->create(['feature_key' => 'qr_tickets', 'value' => 'true']);
        $plan->features()->create(['feature_key' => 'ticket_fee_percent', 'value' => '5']);

        $org = Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
        $event = $org->events()->create([
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'tournament_format' => 'league', 'status' => 'open',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-02',
        ]);
        $category = $event->ticketCategories()->create([
            'name' => 'Reguler', 'price' => 50000, 'quota' => 100, 'is_active' => true,
        ]);

        $orderId = $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
            'ticket_category_id' => $category->id,
            'quantity' => 2,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
        ])->assertCreated()->json('data.order.id');

        return [$org, $event, TicketOrder::findOrFail($orderId)];
    }

    private function release(): void
    {
        Carbon::setTestNow('2026-08-03 12:00:00');
        $this->artisan('wallet:release')->assertSuccessful();
    }

    public function test_refunding_held_money_cancels_the_credit_without_going_negative(): void
    {
        [$org, , $order] = $this->paidOrder();

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/ticket-orders/{$order->id}/refund", ['reason' => 'Event diundur'])
            ->assertOk();

        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_pending' => '0.00',
            'balance_available' => '0.00',
            'total_earned' => '0.00',
        ]);

        // Cancelled, not reversed — no debit row exists.
        $this->assertDatabaseHas('wallet_transactions', ['category' => 'ticket_sale', 'status' => 'cancelled']);
        $this->assertDatabaseMissing('wallet_transactions', ['category' => 'refund']);
    }

    public function test_refunding_released_money_debits_the_available_balance(): void
    {
        [$org, , $order] = $this->paidOrder();
        $this->release();

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/ticket-orders/{$order->id}/refund", ['reason' => 'Pembeli komplain'])
            ->assertOk();

        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_available' => '0.00',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'category' => 'refund',
            'type' => 'debit',
            'amount' => '95000.00',
        ]);
    }

    /**
     * The worst case the design accepts on purpose: the organizer took the
     * money out before the refund landed. The balance goes negative and every
     * further payout is blocked until it recovers.
     */
    public function test_refund_after_withdrawal_goes_negative_and_locks_payouts(): void
    {
        [$org, , $order] = $this->paidOrder();
        $this->release();

        $org->bankAccounts()->create([
            'bank_name' => 'BCA', 'account_number' => '1234567890',
            'account_holder' => 'Budi', 'is_primary' => true,
        ]);

        // Top the balance up to 105.000 so a 100.000 payout (+5.000 fee) empties
        // it exactly — leaving nothing behind to absorb the refund.
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/wallets/{$org->wallet->id}/adjust", [
                'amount' => 10000, 'description' => 'Kompensasi',
            ])->assertOk();

        $withdrawalId = $this->actingAs($org->owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/withdrawals/{$withdrawalId}/complete", [
                'proof_url' => 'https://cdn.example.com/bukti.jpg',
            ])->assertOk();

        $this->assertDatabaseHas('wallets', ['organization_id' => $org->id, 'balance_available' => '0.00']);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/ticket-orders/{$order->id}/refund", ['reason' => 'Chargeback'])
            ->assertOk();

        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_available' => '-95000.00',
        ]);

        // A negative balance can never satisfy amount + fee, so payouts stop.
        $this->actingAs($org->owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount', 'Saldo tidak mencukupi.');

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/admin/wallets?negative=1')
            ->assertOk()
            ->assertJsonPath('data.0.organization_id', $org->id);
    }

    public function test_refund_releases_the_quota_and_voids_the_tickets(): void
    {
        [, , $order] = $this->paidOrder();

        $this->assertSame(2, $order->category->fresh()->sold);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/ticket-orders/{$order->id}/refund", ['reason' => 'Batal'])
            ->assertOk();

        $this->assertSame(0, $order->category->fresh()->sold);
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_an_order_cannot_be_refunded_twice(): void
    {
        [, , $order] = $this->paidOrder();

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/ticket-orders/{$order->id}/refund", ['reason' => 'Batal'])
            ->assertOk();

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/ticket-orders/{$order->id}/refund", ['reason' => 'Batal lagi'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Hanya pesanan lunas yang bisa direfund.');
    }

    public function test_a_checked_in_order_cannot_be_refunded(): void
    {
        [, , $order] = $this->paidOrder();
        $order->tickets()->first()->update(['is_used' => true, 'used_at' => now()]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/ticket-orders/{$order->id}/refund", ['reason' => 'Batal'])
            ->assertStatus(422);
    }

    public function test_non_super_admin_cannot_refund(): void
    {
        [$org, , $order] = $this->paidOrder();

        $this->actingAs($org->owner, 'api')
            ->postJson("/api/v1/admin/ticket-orders/{$order->id}/refund", ['reason' => 'Mau uangnya'])
            ->assertStatus(403);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
