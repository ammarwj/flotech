<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Manual transfer — the fallback while a super admin has the payment gateway
 * switched off (Midtrans is down). The buyer pays the organizer's own bank
 * account and uploads a receipt for an org admin to approve.
 *
 * The invariant that matters: manual money never reaches the platform, so it
 * must never touch the wallet. Crediting it would let an organizer withdraw
 * money we are not holding.
 */
class ManualPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSettings::flush();
    }

    /**
     * The switch is cached `rememberForever` behind a static memo, so writing
     * the row alone changes nothing — the flush is the point.
     */
    private function gateway(bool $enabled): void
    {
        PlatformSettings::put(['payment_gateway_enabled' => $enabled], null);
        PlatformSettings::flush();
    }

    private function orgWithPlan(User $owner, array $features = []): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        $features = ['qr_tickets' => 'true', 'payment_gateway' => 'true', 'ticket_fee_percent' => '5'] + $features;
        foreach ($features as $key => $value) {
            $plan->features()->create(['feature_key' => $key, 'value' => $value]);
        }

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    private function bankAccount(Organization $org, bool $primary = true): void
    {
        $org->bankAccounts()->create([
            'bank_name' => 'BCA',
            'bank_code' => '014',
            'account_number' => '1234567890',
            'account_holder' => 'Flo Event EO',
            'is_primary' => $primary,
        ]);
    }

    private function event(Organization $org): Event
    {
        $event = $org->events()->create([
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'tournament_format' => 'league', 'status' => 'open',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-02',
        ]);

        return $event;
    }

    /** @return array<string, mixed> the purchase response payload */
    private function buy(Organization $org, Event $event, string $categoryId, int $qty = 2): array
    {
        return $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
            'ticket_category_id' => $categoryId,
            'quantity' => $qty,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
        ])->assertCreated()->json('data');
    }

    /**
     * The whole point of the feature, stated as one test.
     *
     * Asserting "the manual order wrote no ledger row" proves nothing on its
     * own — a bug that credits nobody would pass it. The two rails must be
     * compared on the same event, in the same plan, at the same price: the
     * gateway order credits, the manual one does not.
     */
    public function test_manual_order_is_not_credited_but_a_gateway_order_on_the_same_event_is(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner);
        $this->bankAccount($org);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create(['name' => 'Reguler', 'price' => 50000, 'is_active' => true]);

        // Rail 1 — gateway on. MIDTRANS_SERVER_KEY is blank under test
        // (tests/bootstrap.php), so the mock settles it and the wallet is credited.
        $this->gateway(true);
        $gatewayOrder = $this->buy($org, $event, $category->id, 2);

        $this->assertSame('gateway', $gatewayOrder['payment_method']);
        $this->assertNull($gatewayOrder['bank_account']);

        // 2 x 50.000 = 100.000 gross − 5% fee = 95.000 net, held.
        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_pending' => 95000,
        ]);
        $this->assertSame(1, $org->wallet()->first()->transactions()->count());

        // Rail 2 — gateway off. Same org, same event, same price.
        $this->gateway(false);
        $manual = $this->buy($org, $event, $category->id, 2);

        $this->assertSame('manual', $manual['payment_method']);
        $this->assertNull($manual['snap_token']);
        // `mock` must not leak through as "settled" for a manual order.
        $this->assertFalse($manual['mock']);

        $order = TicketOrder::find($manual['order']['id']);
        $this->assertSame('pending', $order->status);
        $this->assertSame(0.0, (float) $order->platform_fee, 'Manual money never reaches us — nothing to take a cut of.');

        // Approve it: the money is now genuinely in the organizer's own account.
        $admin = User::factory()->create(['role' => 'super_admin']);
        $order->attachProof('https://example.test/bukti.png');
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/payments/tickets/{$order->id}/approve")
            ->assertOk();

        $this->assertSame('paid', $order->fresh()->status);

        // The comparison: a paid manual order moved the wallet by exactly nothing.
        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_pending' => 95000,
        ]);
        $this->assertSame(
            1,
            $org->wallet()->first()->transactions()->count(),
            'Only the gateway order may write a ledger entry.',
        );
    }

    public function test_purchase_returns_the_organizers_account_while_the_gateway_is_off(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner);
        $this->bankAccount($org);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create([
            'name' => 'Reguler', 'price' => 50000, 'quota' => 100, 'is_active' => true,
        ]);
        $this->gateway(false);

        $payload = $this->buy($org, $event, $category->id, 2);

        // Unmasked: the buyer has to type this into their banking app.
        $this->assertSame('1234567890', $payload['bank_account']['account_number']);
        $this->assertSame('BCA', $payload['bank_account']['bank_name']);
        $this->assertSame('Flo Event EO', $payload['bank_account']['account_holder']);

        $order = TicketOrder::find($payload['order']['id']);
        $this->assertNotNull($order->payment_deadline_at);
        $this->assertEqualsWithDelta(
            Carbon::now()->addHours(config('payments.manual_order_ttl_hours'))->timestamp,
            $order->payment_deadline_at->timestamp,
            60,
        );

        // Quota is reserved the moment the order exists, gateway or not.
        $this->assertSame(2, $category->fresh()->sold);
        $this->assertSame(2, $order->tickets()->count());
    }

    public function test_gateway_off_without_a_primary_account_refuses_the_sale(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create(['name' => 'Reguler', 'price' => 50000, 'is_active' => true]);
        $this->gateway(false);

        // No bank account: there is nowhere for the money to go. Failing here is
        // the point — the alternative is taking an order nobody can pay.
        $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
            'ticket_category_id' => $category->id,
            'quantity' => 1,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
        ])->assertStatus(422);

        $this->assertSame(0, $category->fresh()->sold, 'A refused sale must not reserve quota.');
        $this->assertDatabaseCount('ticket_orders', 0);
    }

    /**
     * A rejected receipt is not a dead end: the buyer uploads a new one and
     * re-enters the queue. `verified_at` staying null is what allows that.
     */
    public function test_proof_can_be_rejected_then_re_uploaded_and_approved(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner);
        $this->bankAccount($org);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create(['name' => 'Reguler', 'price' => 50000, 'is_active' => true]);
        $this->gateway(false);

        $order = TicketOrder::find($this->buy($org, $event, $category->id, 1)['order']['id']);

        // Nothing to verify until a receipt arrives.
        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/payments")
            ->assertOk()
            ->assertJsonCount(0, 'data.tickets');

        $this->postJson("/api/v1/ticket-orders/{$order->id}/proof", [
            'payment_proof_url' => 'https://example.test/bukti-1.png',
        ])->assertOk()->assertJsonPath('data.awaiting_verification', true);

        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/payments")
            ->assertOk()
            ->assertJsonCount(1, 'data.tickets');

        // Reject: wrong amount transferred.
        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/payments/tickets/{$order->id}/reject", [
                'reason' => 'Nominal tidak sesuai',
            ])->assertOk();

        $order->refresh();
        $this->assertNull($order->payment_proof_url, 'The rejected receipt is cleared so a new one can replace it.');
        $this->assertSame('Nominal tidak sesuai', $order->rejected_reason);
        $this->assertNull($order->verified_at, 'Still unverified — otherwise a re-upload could never be queued again.');
        $this->assertSame('pending', $order->status);
        $this->assertTrue($order->isProofRejected());

        // Second attempt.
        $this->postJson("/api/v1/ticket-orders/{$order->id}/proof", [
            'payment_proof_url' => 'https://example.test/bukti-2.png',
        ])->assertOk();

        $order->refresh();
        $this->assertTrue($order->isAwaitingVerification());
        $this->assertNull($order->rejected_reason, 'A fresh receipt clears the old complaint.');

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/payments/tickets/{$order->id}/approve")
            ->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($order->verified_at);
        $this->assertSame($owner->id, $order->verified_by);
    }

    /**
     * Approving a receipt issues valid tickets on someone's say-so. An operator
     * scans tickets at the gate; they must not be able to mint them.
     */
    public function test_operator_member_cannot_approve_a_payment(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner);
        $this->bankAccount($org);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create(['name' => 'Reguler', 'price' => 50000, 'is_active' => true]);
        $this->gateway(false);

        $order = TicketOrder::find($this->buy($org, $event, $category->id, 1)['order']['id']);
        $order->attachProof('https://example.test/bukti.png');

        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator', 'invited_by' => $owner->id]);

        $this->actingAs($operator, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/payments/tickets/{$order->id}/approve")
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    /**
     * Manual orders get no expiry webhook from Midtrans, so an abandoned one
     * would hold its quota forever. The sweep is what releases it — but only
     * for orders nobody has paid: a receipt awaiting review is the organizer's
     * call, not the scheduler's.
     */
    public function test_expire_manual_sweeps_only_orders_with_no_receipt(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner);
        $this->bankAccount($org);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create([
            'name' => 'Reguler', 'price' => 50000, 'quota' => 100, 'is_active' => true,
        ]);
        $this->gateway(false);

        $abandoned = TicketOrder::find($this->buy($org, $event, $category->id, 2)['order']['id']);
        $waiting = TicketOrder::find($this->buy($org, $event, $category->id, 3)['order']['id']);
        $waiting->attachProof('https://example.test/bukti.png');

        $this->assertSame(5, $category->fresh()->sold);

        // Both are past their deadline; only one of them is abandoned.
        $past = Carbon::now()->subHour();
        $abandoned->update(['payment_deadline_at' => $past]);
        $waiting->update(['payment_deadline_at' => $past]);

        $this->artisan('tickets:expire-manual')->assertSuccessful();

        $this->assertSame('expired', $abandoned->fresh()->status);
        $this->assertSame(0, $abandoned->tickets()->count());

        $this->assertSame('pending', $waiting->fresh()->status, 'A receipt under review is never swept.');
        $this->assertSame(3, $waiting->tickets()->count());

        // Only the abandoned two came back.
        $this->assertSame(3, $category->fresh()->sold);
    }
}
