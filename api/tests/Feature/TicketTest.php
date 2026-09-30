<?php

namespace Tests\Feature;

use App\Mail\TicketPurchasedMail;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TicketTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function orgWithPlan(User $owner, array $features = []): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);
        foreach ($features as $key => $value) {
            $plan->features()->create(['feature_key' => $key, 'value' => $value]);
        }

        // Events in this test run on this plan — planId() is what puts it there.
        $this->testPlan = $plan;

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    private function event(Organization $org): Event
    {
        return $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'tournament_format' => 'league', 'status' => 'open',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-02',
        ]);
    }

    public function test_plan_without_qr_tickets_cannot_create_category(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'false']);
        $event = $this->event($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/ticket-categories", [
                'name' => 'Reguler', 'price' => 50000,
            ])
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'qr_tickets');
    }

    public function test_member_can_create_ticket_category(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true']);
        $event = $this->event($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/ticket-categories", [
                'name' => 'VIP', 'price' => 100000, 'quota' => 100,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'VIP')
            ->assertJsonPath('data.remaining', 100);
    }

    public function test_public_can_buy_free_ticket_and_it_is_auto_paid(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true']);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create(['name' => 'Gratis', 'price' => 0, 'is_active' => true]);

        $orderId = $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
            'ticket_category_id' => $category->id,
            'quantity' => 2,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
            'holder_names' => ['Budi', 'Ani'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.order.status', 'paid')
            ->assertJsonPath('data.order.quantity', 2)
            ->json('data.order.id');

        $this->assertDatabaseCount('tickets', 2);

        $this->getJson("/api/v1/ticket-orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonCount(2, 'data.tickets');
    }

    public function test_scan_validates_then_rejects_reused_ticket(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true']);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create(['name' => 'Gratis', 'price' => 0, 'is_active' => true]);

        $orderId = $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
            'ticket_category_id' => $category->id,
            'quantity' => 1,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
        ])->json('data.order.id');

        $qr = $this->getJson("/api/v1/ticket-orders/{$orderId}")->json('data.tickets.0.qr_code');

        // First scan: valid.
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/scan", ['qr_code' => $qr])
            ->assertOk()
            ->assertJsonPath('data.result', 'valid');

        // Second scan: already used.
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/scan", ['qr_code' => $qr])
            ->assertStatus(409)
            ->assertJsonPath('errors.result', 'used');
    }

    public function test_buyer_gets_a_confirmation_mail_once_the_order_is_paid(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true']);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create(['name' => 'Gratis', 'price' => 0, 'is_active' => true]);

        $orderId = $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
            'ticket_category_id' => $category->id,
            'quantity' => 1,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
        ])->json('data.order.id');

        Mail::assertQueued(
            TicketPurchasedMail::class,
            fn (TicketPurchasedMail $mail) => $mail->order->id === $orderId
                && $mail->hasTo('budi@test.com'),
        );

        // A re-delivered webhook must not send a second confirmation.
        app(TicketService::class)->markPaid(TicketOrder::find($orderId));

        Mail::assertQueuedCount(1);
    }

    public function test_organizer_sees_the_buyer_list(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true']);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create(['name' => 'Gratis', 'price' => 0, 'is_active' => true]);

        $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
            'ticket_category_id' => $category->id,
            'quantity' => 2,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
            'holder_names' => ['Budi', 'Ani'],
        ])->assertCreated();

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/ticket-orders")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.buyer_name', 'Budi')
            ->assertJsonPath('data.0.buyer_email', 'budi@test.com')
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonCount(2, 'data.0.tickets');
    }

    public function test_operator_cannot_read_the_buyer_list(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $org = $this->orgWithPlan($owner, ['qr_tickets' => 'true']);
        $event = $this->event($org);

        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $this->actingAs($operator, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/ticket-orders")
            ->assertStatus(403);
    }

    public function test_scan_rejects_ticket_from_another_event(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true']);
        $eventA = $this->event($org);
        $eventB = $this->event($org);
        $category = $eventA->ticketCategories()->create(['name' => 'Gratis', 'price' => 0, 'is_active' => true]);

        $orderId = $this->postJson("/api/v1/public/events/{$org->slug}/{$eventA->slug}/tickets/purchase", [
            'ticket_category_id' => $category->id,
            'quantity' => 1,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
        ])->json('data.order.id');

        $qr = $this->getJson("/api/v1/ticket-orders/{$orderId}")->json('data.tickets.0.qr_code');

        // Scanning event A's ticket at event B must fail.
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$eventB->id}/scan", ['qr_code' => $qr])
            ->assertStatus(404)
            ->assertJsonPath('errors.result', 'invalid');
    }

    /**
     * The platform's service fee is charged per ticket, the gateway's per
     * transaction — asserted end to end because the multiplier lives in the
     * controller (it passes `quantity` as the unit count) while the arithmetic
     * lives in the calculator, and only a stored order proves both halves ran.
     *
     * Compared against a single-ticket order on the same category and channel:
     * asserting the three-ticket number alone would still pass if the gateway
     * fee were being multiplied along with it.
     */
    public function test_stored_order_charges_the_service_fee_per_ticket_not_per_transaction(): void
    {
        PlatformSettings::put(['service_fee_amount' => 2000], null);
        PlatformSettings::flush();

        $user = User::factory()->create();
        // `payment_gateway` too: these are paid orders, and the rail is what
        // makes a fee exist at all.
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true', 'payment_gateway' => 'true']);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create(['name' => 'Reguler', 'price' => 50000, 'is_active' => true]);

        $buy = fn (int $qty) => TicketOrder::findOrFail(
            $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
                'ticket_category_id' => $category->id,
                'quantity' => $qty,
                'buyer_name' => 'Budi',
                'buyer_email' => 'budi@test.com',
                'payment_channel' => 'va',
            ])->assertCreated()->json('data.order.id')
        );

        $one = $buy(1);
        $three = $buy(3);

        $this->assertEqualsWithDelta(2000.0, (float) $one->service_fee, 0.0001);
        $this->assertEqualsWithDelta(6000.0, (float) $three->service_fee, 0.0001);

        // The bank charges once per Midtrans call however many seats are in the
        // basket — the half that must NOT scale.
        $this->assertEqualsWithDelta((float) $one->gateway_fee, (float) $three->gateway_fee, 0.0001);

        // And it is the charged total, not just a display column: gross_amount
        // is what Midtrans was handed.
        $this->assertEqualsWithDelta(
            150_000 + (float) $three->gateway_fee + 6000.0,
            (float) $three->gross_amount,
            0.0001,
        );
    }

    /**
     * The picker preview and the order path have to agree, or the buyer is
     * quoted one number and charged another. Same quantity through both.
     */
    public function test_channel_preview_quotes_the_same_per_ticket_fee_the_order_charges(): void
    {
        PlatformSettings::put(['service_fee_amount' => 2000], null);
        PlatformSettings::flush();

        $user = User::factory()->create();
        // `payment_gateway` too: these are paid orders, and the rail is what
        // makes a fee exist at all.
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true', 'payment_gateway' => 'true']);
        $event = $this->event($org);
        $category = $event->ticketCategories()->create(['name' => 'Reguler', 'price' => 50000, 'is_active' => true]);

        $preview = collect($this->getJson('/api/v1/public/payment-channels?amount=150000&audience=participant&units=3')
            ->assertOk()
            ->json('data'))
            ->firstWhere('channel', 'va');

        $order = TicketOrder::findOrFail(
            $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
                'ticket_category_id' => $category->id,
                'quantity' => 3,
                'buyer_name' => 'Budi',
                'buyer_email' => 'budi@test.com',
                'payment_channel' => 'va',
            ])->assertCreated()->json('data.order.id')
        );

        $this->assertEqualsWithDelta(6000.0, (float) $preview['service_fee'], 0.0001);
        $this->assertEqualsWithDelta(2000.0, (float) $preview['service_fee_unit'], 0.0001);
        $this->assertSame(3, $preview['units']);
        $this->assertEqualsWithDelta((float) $preview['service_fee'], (float) $order->service_fee, 0.0001);
        $this->assertEqualsWithDelta((float) $preview['total'], (float) $order->gross_amount, 0.0001);
    }

    /**
     * An omitted `units` must quote exactly one fee — every flow except ticket
     * purchase buys one thing, and a default that multiplied would overcharge
     * registrations and plan checkouts through the same endpoint.
     */
    public function test_channel_preview_without_units_quotes_a_single_fee(): void
    {
        PlatformSettings::put(['service_fee_amount' => 2000], null);
        PlatformSettings::flush();

        $preview = collect($this->getJson('/api/v1/public/payment-channels?amount=150000&audience=participant')
            ->assertOk()
            ->json('data'))
            ->firstWhere('channel', 'va');

        $this->assertSame(1, $preview['units']);
        $this->assertEqualsWithDelta(2000.0, (float) $preview['service_fee'], 0.0001);
    }
}
