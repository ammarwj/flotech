<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\RaceEntry;
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
 * Which instrument a payment settled with, and what the dashboard makes of it.
 *
 * `payment_type` is reported by Midtrans only once a payment settles, so the
 * webhook is the only door that knows it — and it must never be confused with
 * the order's `payment_channel`, which was already chosen before paying. Most
 * tests here are comparisons for exactly that reason.
 */
class PaymentInstrumentTest extends TestCase
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
     * A pending fun run entry, with the period and category it needs.
     *
     * Its own event, because `sport_type` is what makes the race tables apply
     * and the ticket fixture's event is futsal. Built without a paid plan order
     * for the same reason event() is — see its docblock.
     */
    private function raceEntry(Organization $org, string $midtransId = 'RUN-ABCDEFGHIJ'): RaceEntry
    {
        $event = $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Run', 'slug' => 'run-'.uniqid(),
            'sport_type' => 'fun_run', 'status' => 'open',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-01',
        ]);

        $period = $event->racePeriods()->create([
            'name' => 'Early Bird',
            'starts_at' => '2026-07-01T00:00:00+07:00',
            'ends_at' => '2026-07-10T23:59:59+07:00',
        ]);
        $category = $event->raceCategories()->create(['name' => '5K', 'slug' => '5k']);
        $category->prices()->create(['race_period_id' => $period->id, 'price' => 75000]);

        return $category->entries()->create([
            'event_id' => $event->id,
            'full_name' => 'Ammar',
            'email' => 'ammar@test.com',
            'race_period_id' => $period->id,
            'period_name' => 'Early Bird',
            'price' => 75000,
            'status' => 'pending',
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
     * All four rails in one test. One rail on its own would pass while the
     * other arms were never wired — which is exactly the state this change
     * found the controller in twice: first the TIX-/REG- arms never receiving
     * the instrument, then the RUN- arm missing from `match` altogether.
     */
    public function test_all_four_rails_store_the_instrument(): void
    {
        // Settling a plan order mails a receipt PDF, and dompdf's own output
        // buffer is what PHPUnit flags as risky. The document is covered by
        // PlanOrderBillingTest; what this test is about is the column.
        Notification::fake();

        $org = $this->org();
        $event = $this->event($org);

        $order = $this->ticketOrder($event);
        $team = $this->team($event);
        $entry = $this->raceEntry($org);
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
        $this->notify($this->payload($entry->midtrans_order_id, '75000.00', ['payment_type' => 'echannel']))->assertOk();
        $this->notify($this->payload($planOrder->midtrans_order_id, '350000.00', ['payment_type' => 'gopay']))->assertOk();

        $this->assertSame('qris', $order->fresh()->payment_type);
        $this->assertSame('bank_transfer', $team->fresh()->payment_type);
        // `va` channel, `echannel` instrument — the pair that proves the two
        // columns answer different questions, taken from a real dev row.
        $entry = $entry->fresh();
        $this->assertSame('echannel', $entry->payment_type);
        $this->assertSame('va', $entry->payment_channel);
        $this->assertSame('gopay', $planOrder->fresh()->payment_type);
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

    /**
     * Every rail reaches the figures, compared against the catalogue of rails
     * rather than a literal list.
     *
     * A rail left out of SOURCES does not read as zero anywhere — its revenue is
     * simply absent from every number on the page, which is indistinguishable
     * from nobody having paid. That is exactly how the fun run rail arrived:
     * added on its own branch, after the breakdown was written.
     *
     * The comparison that matters is `totals` against the sum of `by_source`:
     * asserting a race row exists would still pass if the rail were counted in
     * its own card but left out of the roll-up above it.
     */
    public function test_every_rail_reaches_the_breakdown(): void
    {
        $org = $this->org();
        $event = $this->event($org);

        $this->notify($this->payload(
            $this->ticketOrder($event)->midtrans_order_id, '100000.00', ['payment_type' => 'qris'],
        ))->assertOk();
        $this->notify($this->payload(
            $this->team($event)->midtrans_order_id, '150000.00', ['payment_type' => 'bank_transfer'],
        ))->assertOk();
        $this->notify($this->payload(
            $this->raceEntry($org)->midtrans_order_id, '75000.00', ['payment_type' => 'echannel'],
        ))->assertOk();

        $data = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/payment-stats')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['by_source']['race_entry']['count']);
        $this->assertEquals(75000, $data['by_source']['race_entry']['gross']);

        // Three payments, and the roll-up is the sum of the rails beside it.
        $this->assertSame(3, $data['totals']['count']);
        $this->assertSame(
            $data['totals']['count'],
            array_sum(array_column($data['by_source'], 'count')),
        );
        $this->assertEquals(325000, $data['totals']['gross']);
    }

    /**
     * The event filter, compared against a second event in the **same
     * organization** so the org cannot explain the difference.
     *
     * Asserting the chosen event's own figure alone would pass a build that
     * ignored `event_id` entirely whenever only one event had revenue, which is
     * every fixture that does not go out of its way to build two. Both halves
     * of the pair are asserted, plus the unfiltered roll-up above them.
     */
    public function test_the_event_filter_narrows_the_figures_to_one_event(): void
    {
        $org = $this->org();
        $cup = $this->event($org);
        $other = $this->event($org);

        $this->notify($this->payload(
            $this->ticketOrder($cup, 'TIX-CUPAAAAAAA')->midtrans_order_id,
            '100000.00',
            ['payment_type' => 'qris'],
        ))->assertOk();
        $this->notify($this->payload(
            $this->ticketOrder($other, 'TIX-OTHERBBBBB')->midtrans_order_id,
            '100000.00',
            ['payment_type' => 'qris'],
        ))->assertOk();

        $admin = $this->superAdmin();

        $this->assertSame(2, $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/payment-stats')
            ->assertOk()->json('data.totals.count'));

        foreach ([$cup, $other] as $event) {
            $data = $this->actingAs($admin, 'api')
                ->getJson('/api/v1/admin/payment-stats?event_id='.$event->id)
                ->assertOk()->json('data');

            $this->assertSame(1, $data['totals']['count']);
            $this->assertSame(1, $data['by_source']['ticket_order']['count']);
            // The breakdowns are filtered by the same query, not just the cards.
            $this->assertSame(1, collect($data['by_payment_type'])
                ->firstWhere('payment_type', 'qris')['count']);
        }
    }

    /**
     * A paid plan credit nobody has spent yet carries no `event_id`, so it falls
     * out of an event-scoped figure — and stays in the unfiltered one.
     *
     * Compared both ways round in one test: asserting only that the filtered
     * figure excludes it would pass a build where the credit was never counted
     * at all, which is a very different bug on a page whose whole job is to say
     * what the platform took in.
     */
    public function test_an_unclaimed_plan_credit_leaves_the_event_scoped_figures(): void
    {
        // Settling a plan order mails a receipt PDF; see the rails test above.
        Notification::fake();

        $org = $this->org();
        $event = $this->event($org);

        $this->notify($this->payload(
            $this->ticketOrder($event)->midtrans_order_id,
            '100000.00',
            ['payment_type' => 'qris'],
        ))->assertOk();

        $plan = Plan::create(['name' => 'Pro X', 'slug' => 'pro-'.uniqid(), 'price' => 350000]);
        $credit = $org->planOrders()->create([
            'plan_id' => $plan->id,
            'amount' => 350000,
            'status' => 'past_due',
            'payment_method' => 'gateway',
            'payment_channel' => 'va',
            'midtrans_order_id' => 'PLN-IDLEAAAAAA',
        ]);
        $this->notify($this->payload($credit->midtrans_order_id, '350000.00', [
            'payment_type' => 'gopay',
        ]))->assertOk();

        // The credit is real revenue and is counted while nothing is filtered.
        $this->assertNull($credit->fresh()->event_id);
        $admin = $this->superAdmin();

        $all = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/payment-stats')
            ->assertOk()->json('data');
        $this->assertSame(1, $all['by_source']['event_plan_order']['count']);
        $this->assertSame(2, $all['totals']['count']);

        $scoped = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/payment-stats?event_id='.$event->id)
            ->assertOk()->json('data');
        $this->assertSame(0, $scoped['by_source']['event_plan_order']['count']);
        $this->assertSame(1, $scoped['totals']['count']);
    }

    public function test_a_regular_user_cannot_read_payment_stats(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson('/api/v1/admin/payment-stats')
            ->assertStatus(403);
    }
}
