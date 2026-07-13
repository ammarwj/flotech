<?php

namespace Tests\Feature;

use App\Mail\TicketPurchasedMail;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TicketTest extends TestCase
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
        return $org->events()->create([
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

    public function test_ticket_limit_blocks_excess_quota(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true', 'max_tickets_per_event' => '100']);
        $event = $this->event($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/ticket-categories", [
                'name' => 'Reguler', 'price' => 50000, 'quota' => 150,
            ])
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'max_tickets_per_event');
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
}
