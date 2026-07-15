<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\TicketService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Income: a paid ticket order or registration fee credits the organizer's
 * wallet with the net (gross − platform fee), held until the event is over.
 */
class WalletTest extends TestCase
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

    private function event(Organization $org): Event
    {
        $event = $org->events()->create([
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal', 'status' => 'open',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-02',
        ]);

        $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league',
            'registration_fee' => 0, 'sort_order' => 0,
        ]);

        return $event->load('categories');
    }

    private function buy(Organization $org, Event $event, string $categoryId, int $qty = 2): string
    {
        return $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
            'ticket_category_id' => $categoryId,
            'quantity' => $qty,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
        ])->assertCreated()->json('data.order.id');
    }

    public function test_paid_ticket_order_credits_net_amount_as_pending(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true', 'ticket_fee_percent' => '5']);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create(['name' => 'Reguler', 'price' => 50000, 'is_active' => true]);

        $this->buy($org, $event, $category->id, 2);

        // 2 x 50.000 = 100.000 gross, 5% platform fee = 5.000, net = 95.000.
        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_pending' => '95000.00',
            'balance_available' => '0.00',
            'total_earned' => '95000.00',
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'organization_id' => $org->id,
            'type' => 'credit',
            'category' => 'ticket_sale',
            'status' => 'pending',
            'gross_amount' => '100000.00',
            'fee_amount' => '5000.00',
            'amount' => '95000.00',
        ]);
    }

    public function test_free_ticket_order_writes_no_ledger_entry(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true']);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create(['name' => 'Gratis', 'price' => 0, 'is_active' => true]);

        $this->buy($org, $event, $category->id, 2);

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_plan_without_fee_percent_credits_full_amount(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true']);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create(['name' => 'Reguler', 'price' => 50000, 'is_active' => true]);

        $this->buy($org, $event, $category->id, 1);

        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_pending' => '50000.00',
        ]);
    }

    public function test_paid_registration_fee_credits_wallet(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['registration_fee_percent' => '10']);
        $event = $this->event($org);
        $event->categories->first()->update(['registration_fee' => 150000]);

        // Registration needs an account behind the team (see RegistrationTest).
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", [
                'category_id' => $event->categories->first()->id,
                'name' => 'Garuda FC',
                'contact_name' => 'Budi',
                'contact_phone' => '08123456789',
                'players' => [
                    ['full_name' => 'Player 1', 'jersey_number' => '1'],
                    ['full_name' => 'Player 2', 'jersey_number' => '2'],
                ],
            ])->assertCreated();

        // 150.000 gross − 10% = 135.000 net.
        $this->assertDatabaseHas('wallet_transactions', [
            'organization_id' => $org->id,
            'category' => 'registration_fee',
            'status' => 'pending',
            'amount' => '135000.00',
            'fee_amount' => '15000.00',
        ]);
    }

    public function test_redelivered_settlement_does_not_credit_twice(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true', 'ticket_fee_percent' => '5']);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create(['name' => 'Reguler', 'price' => 50000, 'is_active' => true]);

        $orderId = $this->buy($org, $event, $category->id, 2);
        $order = TicketOrder::findOrFail($orderId);

        // Simulate the webhook arriving again for an already-settled order, and
        // then a credit attempt that bypasses markPaid's early return entirely.
        app(TicketService::class)->markPaid($order->fresh());
        app(WalletService::class)->creditTicketOrder($order->fresh()->load('event.organization'));

        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_pending' => '95000.00',
        ]);
    }

    public function test_midtrans_webhook_settlement_is_idempotent(): void
    {
        config()->set('services.midtrans.server_key', 'test-server-key');

        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true', 'ticket_fee_percent' => '5']);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create(['name' => 'Reguler', 'price' => 50000, 'is_active' => true]);

        // Build a pending order directly: with a server key present the public
        // purchase endpoint would try to reach Midtrans over HTTP.
        $order = TicketOrder::create([
            'event_id' => $event->id,
            'ticket_category_id' => $category->id,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
            'quantity' => 2,
            'unit_price' => 50000,
            'total_price' => 100000,
            'platform_fee' => 5000,
            'status' => 'pending',
            'midtrans_order_id' => 'TIX-ABCDEFGHIJ',
        ]);

        $payload = [
            'order_id' => $order->midtrans_order_id,
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'transaction_status' => 'settlement',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'test-server-key',
        );

        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_pending' => '95000.00',
        ]);
    }
}
