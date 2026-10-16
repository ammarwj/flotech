<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\PaymentNotification;
use App\Models\Plan;
use App\Models\Team;
use App\Models\TicketCategory;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * What Midtrans tells us, and what survives of it.
 *
 * Two things under test, and they are deliberately different kinds: the
 * instrument (`payment_type`) landing on the order rows, and every notification
 * landing in `payment_notifications` as an audit trail that must never be
 * mistaken for the money.
 */
class MidtransNotificationTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private const KEY = 'test-server-key';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.midtrans.server_key', self::KEY);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function org(): Organization
    {
        return $this->orgFor(User::factory()->create());
    }

    /**
     * An event on a full plan, but **without** the paid plan order `eventOn()`
     * would mint beside it: a plan purchase is platform revenue, so scenery
     * like that lands in the figures under test and makes every count one too
     * high. The only plan orders in this file are the ones a test puts there
     * deliberately.
     */
    private function event(Organization $org): Event
    {
        $event = $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(),
            'sport_type' => 'futsal', 'status' => 'open',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-10',
        ]);

        $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league',
            'registration_fee' => 150000, 'sort_order' => 0,
        ]);

        return $event->load('categories');
    }

    /**
     * A pending gateway order, built directly: with a server key present the
     * public purchase endpoint would try to reach Midtrans over HTTP.
     *
     * `payment_channel` is set on purpose — it is half of the first comparison
     * below, and the thing `payment_type` must not be confused with.
     */
    private function ticketOrder(Event $event, string $midtransId = 'TIX-ABCDEFGHIJ'): TicketOrder
    {
        $category = TicketCategory::create([
            'event_id' => $event->id, 'name' => 'Reguler', 'price' => 50000, 'is_active' => true,
        ]);

        return TicketOrder::create([
            'event_id' => $event->id,
            'ticket_category_id' => $category->id,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
            'quantity' => 2,
            'unit_price' => 50000,
            'total_price' => 100000,
            'platform_fee' => 0,
            'status' => 'pending',
            'payment_method' => 'gateway',
            'payment_channel' => 'ewallet',
            'midtrans_order_id' => $midtransId,
        ]);
    }

    private function team(Event $event, string $midtransId = 'REG-ABCDEFGHIJ'): Team
    {
        return Team::create([
            'event_id' => $event->id,
            'category_id' => $event->categories->first()->id,
            'name' => 'Garuda FC',
            'contact_name' => 'Budi',
            'contact_phone' => '08123456789',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'payment_amount' => 150000,
            'payment_method' => 'gateway',
            'payment_channel' => 'va',
            'midtrans_order_id' => $midtransId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(string $orderId, string $gross, array $extra = []): array
    {
        $payload = [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => $gross,
            'transaction_status' => 'settlement',
            'transaction_id' => 'trx-'.substr(md5($orderId), 0, 12),
            'fraud_status' => 'accept',
            'transaction_time' => '2026-10-16 10:30:00',
            ...$extra,
        ];

        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::KEY,
        );

        return $payload;
    }

    /** @param  array<string, mixed>  $payload */
    private function notify(array $payload): TestResponse
    {
        return $this->postJson('/api/v1/webhooks/midtrans', $payload);
    }

    /**
     * The whole reason there are two columns: `payment_channel` is the fee group
     * the buyer picked before paying and the gateway fee was computed from;
     * `payment_type` is the instrument that settled it. Asserting only the new
     * column would pass an implementation that overwrote the old one and left
     * the fee impossible to reconcile.
     */
    public function test_payment_type_is_stored_without_touching_payment_channel(): void
    {
        $order = $this->ticketOrder($this->event($this->org()));

        $this->notify($this->payload($order->midtrans_order_id, '100000.00', [
            'payment_type' => 'qris',
        ]))->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('qris', $fresh->payment_type);
        $this->assertSame('ewallet', $fresh->payment_channel);
        $this->assertSame('paid', $fresh->status);
    }

    /**
     * All three rails in one test. One rail on its own would pass while the two
     * other arms were never wired — which is exactly the state this change
     * found the controller in.
     */
    public function test_all_three_rails_store_the_instrument(): void
    {
        // Settling a plan order mails a receipt PDF, and dompdf's own output
        // buffer is what PHPUnit flags as risky. The document is covered by
        // PlanOrderBillingTest; what this test is about is the column.
        Notification::fake();

        $org = $this->org();
        $event = $this->event($org);

        $order = $this->ticketOrder($event);
        $team = $this->team($event);
        $plan = Plan::create(['name' => 'Pro X', 'slug' => 'pro-'.uniqid(), 'price' => 350000]);
        $planOrder = $org->planOrders()->create([
            'plan_id' => $plan->id,
            'amount' => 350000,
            'status' => 'past_due',
            'payment_method' => 'gateway',
            'payment_channel' => 'va',
            'midtrans_order_id' => 'PLN-ABCDEFGHIJ',
        ]);

        $this->notify($this->payload($order->midtrans_order_id, '100000.00', ['payment_type' => 'qris']))->assertOk();
        $this->notify($this->payload($team->midtrans_order_id, '150000.00', ['payment_type' => 'bank_transfer']))->assertOk();
        $this->notify($this->payload($planOrder->midtrans_order_id, '350000.00', ['payment_type' => 'gopay']))->assertOk();

        $this->assertSame('qris', $order->fresh()->payment_type);
        $this->assertSame('bank_transfer', $team->fresh()->payment_type);
        $this->assertSame('gopay', $planOrder->fresh()->payment_type);
    }

    /**
     * The log appends per delivery; the money does not. Asserting the row count
     * alone would pass a build that also double-credited, and asserting the
     * credit alone would pass one that silently deduped the log and lost the
     * evidence of a re-delivery.
     */
    public function test_a_redelivered_notification_appends_a_row_but_not_a_credit(): void
    {
        $order = $this->ticketOrder($this->event($this->org()));
        $payload = $this->payload($order->midtrans_order_id, '100000.00', ['payment_type' => 'qris']);

        $this->notify($payload)->assertOk();
        $this->notify($payload)->assertOk();

        $this->assertDatabaseCount('payment_notifications', 2);
        $this->assertDatabaseCount('wallet_transactions', 1);

        $types = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/payment-stats')
            ->assertOk()
            ->json('data.by_payment_type');

        $qris = collect($types)->firstWhere('payment_type', 'qris');
        $this->assertSame(1, $qris['count']);
        $this->assertEquals(100000, $qris['gross']);
    }

    /**
     * Logged, and still rejected. Comparing the order before and after is the
     * point: a build that recorded the row *after* the gate would pass an
     * assertion on the 403 alone, and so would one that let the payload through.
     */
    public function test_an_invalid_signature_is_logged_but_moves_nothing(): void
    {
        $order = $this->ticketOrder($this->event($this->org()));

        $payload = $this->payload($order->midtrans_order_id, '100000.00', ['payment_type' => 'qris']);
        $payload['signature_key'] = 'nonsense';

        $this->notify($payload)->assertStatus(403);

        $row = PaymentNotification::firstOrFail();
        $this->assertFalse($row->signature_valid);
        $this->assertFalse($row->handled);
        $this->assertSame('qris', $row->payment_type);

        $fresh = $order->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->payment_type);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    /**
     * The failure the controller's docblock calls out: a 404 that Midtrans
     * retries and then abandons, leaving nothing behind. `handled` is what makes
     * it findable, and it is what tells this case apart from a bad signature.
     */
    public function test_an_unknown_order_is_logged_as_unhandled(): void
    {
        $this->notify($this->payload('TIX-NOSUCHORDER', '100000.00', [
            'payment_type' => 'qris',
        ]))->assertStatus(404);

        $row = PaymentNotification::firstOrFail();
        $this->assertTrue($row->signature_valid);
        $this->assertFalse($row->handled);
        $this->assertNull($row->source_type);
    }

    /** The full response is kept, including fields no column of ours reads. */
    public function test_the_full_payload_is_kept(): void
    {
        $order = $this->ticketOrder($this->event($this->org()));

        $this->notify($this->payload($order->midtrans_order_id, '100000.00', [
            'payment_type' => 'qris',
            'acquirer' => 'gopay',
            'merchant_id' => 'G123456',
        ]))->assertOk();

        $row = PaymentNotification::firstOrFail();
        $this->assertSame('gopay', $row->payload['acquirer']);
        $this->assertSame('G123456', $row->payload['merchant_id']);
        $this->assertSame('accept', $row->fraud_status);
        $this->assertSame('100000.00', $row->gross_amount);
        $this->assertNotNull($row->transaction_time);
        $this->assertSame('ticket_order', $row->source_type);
        $this->assertSame($order->id, $row->source_id);
    }

    /**
     * The other four doors into markPaid() have no instrument to report, and a
     * null that assigned instead of defaulting would blank a value the webhook
     * already stored. Comparing before and after is the only way to see it: an
     * assertion that the order is `paid` passes either way.
     */
    public function test_a_later_settle_without_an_instrument_does_not_blank_it(): void
    {
        $order = $this->ticketOrder($this->event($this->org()));

        $this->notify($this->payload($order->midtrans_order_id, '100000.00', [
            'payment_type' => 'qris',
        ]))->assertOk();

        // Reaches the update directly rather than through the early return, so
        // this is a real test of the `??` and not of markPaid()'s idempotency.
        $order->fresh()->update(['status' => 'pending']);
        app(TicketService::class)->markPaid($order->fresh());

        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame('qris', $fresh->payment_type);
    }

    /**
     * Null is revenue too — a manual transfer, cash at the gate, a free order,
     * or anything settled before the column existed. Dropping the group would
     * make the number of columns change from request to request.
     */
    public function test_the_breakdown_keeps_orders_with_no_instrument(): void
    {
        $org = $this->org();
        $event = $this->event($org);

        $this->notify($this->payload(
            $this->ticketOrder($event, 'TIX-GATEWAY01')->midtrans_order_id,
            '100000.00',
            ['payment_type' => 'qris'],
        ))->assertOk();

        // A box-office sale: real money, no Midtrans, so no instrument.
        $onsite = $this->ticketOrder($event, 'TIX-ONSITE001');
        $onsite->update(['payment_method' => 'onsite', 'payment_channel' => null]);
        app(TicketService::class)->markPaid($onsite->fresh());

        $data = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/payment-stats')
            ->assertOk()
            ->json('data');

        $byType = collect($data['by_payment_type']);
        $this->assertSame(1, $byType->firstWhere('payment_type', 'qris')['count']);
        $this->assertSame(1, $byType->firstWhere('payment_type', 'unknown')['count']);

        // Both orders are counted, and the totals are the sum of the rails
        // printed beside them — not a second query that can disagree.
        $this->assertSame(2, $data['totals']['count']);
        $this->assertSame(2, $data['by_source']['ticket_order']['count']);
        $this->assertSame(0, $data['by_source']['team']['count']);
        $this->assertEquals(
            $data['totals']['gross'],
            array_sum(array_column($data['by_source'], 'gross')),
        );
    }

    public function test_date_filters_narrow_the_figures(): void
    {
        $order = $this->ticketOrder($this->event($this->org()));
        $this->notify($this->payload($order->midtrans_order_id, '100000.00', [
            'payment_type' => 'qris',
        ]))->assertOk();

        $admin = $this->superAdmin();

        $this->assertSame(1, $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/payment-stats?from='.now()->toDateString())
            ->assertOk()->json('data.totals.count'));

        $this->assertSame(0, $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/payment-stats?to='.now()->subDay()->toDateString())
            ->assertOk()->json('data.totals.count'));
    }

    /**
     * The channel's human name comes from the config the checkout picker reads,
     * so the dashboard cannot disagree with what the buyer was shown. Compared
     * against the config rather than against a literal: a hardcoded "QRIS" here
     * would keep passing after someone renames the channel, which is the drift
     * the label exists to prevent.
     */
    public function test_channel_rows_carry_the_label_the_buyer_was_shown(): void
    {
        $order = $this->ticketOrder($this->event($this->org()));
        $this->notify($this->payload($order->midtrans_order_id, '100000.00', [
            'payment_type' => 'qris',
        ]))->assertOk();

        $channels = collect($this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/payment-stats')
            ->assertOk()
            ->json('data.by_channel'));

        $this->assertSame(
            config('payment_fees.channels.ewallet.label'),
            $channels->firstWhere('payment_channel', 'ewallet')['label'],
        );
        // The instruments are NOT labelled: Midtrans names them, and there is no
        // catalogue of them to read a label out of.
        $types = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/payment-stats')
            ->assertOk()
            ->json('data.by_payment_type');
        $this->assertArrayNotHasKey('label', $types[0]);
    }

    public function test_a_regular_user_cannot_read_payment_stats(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson('/api/v1/admin/payment-stats')
            ->assertStatus(403);
    }
}
