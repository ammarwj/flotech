<?php

namespace Tests\Feature;

use App\Mail\TicketPurchasedMail;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\TicketCategory;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\ParticipantDocumentService;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The box office: the organizer selling a ticket at the venue.
 *
 * Every case here is written as a *comparison*, because almost none of these
 * rules can be proved from one outcome:
 *
 * - asserting an onsite sale writes no ledger entry says nothing, because a
 *   free ticket writes none either — the gateway sale beside it is what makes
 *   the claim;
 * - asserting the box office sells a closed category says nothing about whether
 *   the public door still refuses it;
 * - asserting the owner can sell passes identically under `org.admin`, so the
 *   operator is the actor that carries the access decision.
 */
class TicketSaleTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Inside the three-day window below, mid-morning WIB. Two rules read
        // the clock — wallet holds and the box office's "valid today" — and
        // both would otherwise depend on when CI runs.
        Carbon::setTestNow('2026-11-15 03:00:00'); // 10:00 WIB on day two
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function orgWithTickets(User $owner, array $extra = []): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);

        // `$extra` first: array union keeps the LEFT side, so defaults written
        // first would silently swallow a case that means to turn one off.
        foreach ($extra + ['qr_tickets' => 'true', 'payment_gateway' => 'true'] as $key => $value) {
            $plan->features()->create(['feature_key' => $key, 'value' => $value]);
        }

        $this->testPlan = $plan;

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    /** A three-day event: 14–16 November, so "today" is 15 Nov WIB. */
    private function event(Organization $org, array $attrs = []): Event
    {
        return $org->events()->create($attrs + [
            'plan_id' => $this->planId(),
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'tournament_format' => 'league', 'status' => 'open',
            'start_date' => '2026-11-14', 'end_date' => '2026-11-16',
            'timezone' => 'Asia/Jakarta',
        ]);
    }

    /** @param  list<string>  $dates */
    private function category(Event $event, string $mode = 'none', array $dates = [], array $attrs = []): TicketCategory
    {
        $category = $event->ticketCategories()->create($attrs + [
            'name' => 'Kategori '.$mode,
            'day_mode' => $mode,
            'price' => 50000,
            'is_active' => true,
        ]);

        foreach ($dates as $date) {
            $category->days()->create(['event_date' => $date]);
        }

        return $category->fresh()->load('days');
    }

    /**
     * A sale as the box office makes it.
     *
     * No `rail` is sent — the server derives it from the event — so
     * `payment_channel` goes along unconditionally: a cash sale ignores it, and
     * leaving it out would make every gateway case 422 for the wrong reason.
     */
    private function sell(
        User $actor,
        Organization $org,
        Event $event,
        TicketCategory $category,
        int $seats = 1,
        array $dates = [],
    ): TestResponse {
        return $this->actingAs($actor, 'api')->postJson(
            "/api/v1/organizations/{$org->id}/events/{$event->id}/ticket-sales",
            array_filter([
                'ticket_category_id' => $category->id,
                'quantity' => $seats,
                'buyer_name' => 'Budi',
                'buyer_email' => 'budi@test.com',
                'payment_channel' => 'va',
                'dates' => $dates === [] ? null : $dates,
            ], fn ($v) => $v !== null),
        );
    }

    /** An event whose rail is manual, so its box office takes cash. */
    private function cashEvent(Organization $org): Event
    {
        $event = $this->event($org, ['payment_method' => 'manual']);

        // A manual event needs somewhere a transfer could land, so the org has
        // one — otherwise `destinationFor()` would be what refuses, and these
        // cases would pass for the wrong reason.
        $org->bankAccounts()->create([
            'bank_name' => 'BCA', 'account_number' => '1234567890',
            'account_holder' => 'Org', 'is_primary' => true,
        ]);

        return $event;
    }

    /**
     * The rail is the event's, never the till's choice.
     *
     * Two events of the same organization on the *same plan*, so the plan
     * cannot explain the difference — only `events.payment_method` can. A till
     * that could pick would let the gateway event take cash off the books, and
     * asserting one event alone would stay green with the choice handed back to
     * the client.
     */
    public function test_rail_follows_the_event_not_the_till(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);

        $online = $this->event($org);
        $cash = $this->cashEvent($org);

        $this->sell($user, $org, $online, $this->category($online))
            ->assertCreated()
            ->assertJsonPath('data.rail', 'gateway');

        $this->sell($user, $org, $cash, $this->category($cash))
            ->assertCreated()
            ->assertJsonPath('data.rail', 'onsite');
    }

    /**
     * And the global switch overrides, rather than reconciling: the gateway
     * event above becomes a cash box office during an outage.
     *
     * Compared against itself before and after the switch, which is the only
     * way to show the switch is what moved it — a second event would leave
     * `events.payment_method` as an explanation.
     */
    public function test_global_outage_turns_a_gateway_event_into_a_cash_box_office(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event);

        $this->sell($user, $org, $event, $category)
            ->assertCreated()
            ->assertJsonPath('data.rail', 'gateway');

        $org->bankAccounts()->create([
            'bank_name' => 'BCA', 'account_number' => '1234567890',
            'account_holder' => 'Org', 'is_primary' => true,
        ]);

        PlatformSettings::put(['payment_gateway_enabled' => false], null);

        $this->sell($user, $org, $event, $category)
            ->assertCreated()
            ->assertJsonPath('data.rail', 'onsite');
    }

    /**
     * The invariant the cash rail exists for: money in the staff's hand never
     * passes through the platform, so the wallet must not claim it.
     *
     * Compared against a gateway sale of the same price in another event on the
     * same plan, because "no ledger entry" is also what a free ticket produces
     * — asserting the cash side alone would stay green with the guard removed
     * entirely.
     */
    public function test_cash_sale_skips_the_wallet_while_gateway_credits_it(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);

        $cash = $this->cashEvent($org);
        $this->sell($user, $org, $cash, $this->category($cash), 2)->assertCreated();

        // Nothing at all, not merely a smaller number.
        $this->assertDatabaseCount('wallet_transactions', 0);

        $online = $this->event($org);
        $this->sell($user, $org, $online, $this->category($online), 2)->assertCreated();

        // Mock Midtrans (no server key in tests) settles the gateway order, so
        // this is the same settlement path a webhook would take.
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseHas('wallet_transactions', [
            'organization_id' => $org->id,
            'category' => 'ticket_sale',
            'amount' => '100000.00',
        ]);
    }

    /**
     * A cash order is paid the moment it is created — that is what the rail
     * means — and it still gets its receipt number and its buyer mail.
     *
     * The absent `redirect_url` is the load-bearing half: both rails reach
     * `paid` in tests because mock Midtrans settles too, so the only thing that
     * tells them apart here is that cash opened no payment at all.
     */
    public function test_cash_order_is_paid_on_the_spot_with_a_receipt_and_mail(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->cashEvent($org);
        $category = $this->category($event);

        $response = $this->sell($user, $org, $event, $category, 2)
            ->assertCreated()
            ->assertJsonPath('data.rail', 'onsite')
            ->assertJsonPath('data.settled', true)
            // No gateway payment was opened, so there is nothing to send the
            // buyer to — the dialog's QR pane must not appear for cash.
            ->assertJsonPath('data.redirect_url', null)
            ->assertJsonPath('data.snap_token', null);

        $order = TicketOrder::findOrFail($response->json('data.order.id'));

        $this->assertSame('onsite', $order->payment_method);
        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($order->receipt_number);
        // No fee is charged on cash: there is no gateway to pay.
        $this->assertEqualsWithDelta(0.0, (float) $order->gateway_fee, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $order->service_fee, 0.0001);
        // 2 seats on a dateless category = 2 QRs.
        $this->assertSame(2, $order->tickets()->count());

        Mail::assertQueued(TicketPurchasedMail::class);
    }

    /**
     * A manual-rail event's box office takes cash — it never writes a `manual`
     * order. There is nobody to upload a receipt: the buyer is standing here.
     *
     * Compared against the public door on the same event, which *does* write a
     * manual order, because that is the substitution being claimed.
     */
    public function test_manual_event_sells_cash_at_the_till_and_manual_online(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->cashEvent($org);
        $category = $this->category($event);

        $till = TicketOrder::findOrFail(
            $this->sell($user, $org, $event, $category)->assertCreated()->json('data.order.id'),
        );
        $this->assertSame('onsite', $till->payment_method);

        $shop = TicketOrder::findOrFail(
            $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
                'ticket_category_id' => $category->id,
                'quantity' => 1,
                'buyer_name' => 'Budi',
                'buyer_email' => 'budi@test.com',
                'payment_channel' => 'va',
            ])->assertCreated()->json('data.order.id'),
        );
        $this->assertSame('manual', $shop->payment_method);

        // And only one of them is settled: the cash one.
        $this->assertSame('paid', $till->status);
        $this->assertSame('pending', $shop->status);
    }

    /**
     * The one place the box office diverges from the public door.
     *
     * A venue sells after the online window has closed, and the staff asking is
     * the organizer. Compared against the public door on the *same* category,
     * because asserting the box office succeeds proves nothing about whether
     * the window is still enforced anywhere.
     */
    public function test_closed_sale_window_stops_the_public_door_but_not_the_box_office(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event, 'none', [], [
            // Sales ended yesterday.
            'sale_end' => '2026-11-14 00:00:00',
        ]);

        $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
            'ticket_category_id' => $category->id,
            'quantity' => 1,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
            'payment_channel' => 'va',
        ])->assertStatus(422);

        $this->sell($user, $org, $event, $category)->assertCreated();
    }

    /**
     * Quota still binds. Ignoring the clock is not ignoring capacity — the
     * same reasoning as the offline team entry re-checking both ceilings.
     *
     * Compared across two dates of one category: a per-date refusal that read
     * the category's own `remaining()` would let a sold-out day be bought
     * through a spare one, and that bug is invisible from a single date.
     */
    public function test_per_day_quota_is_enforced_per_date(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event, 'per_day', ['2026-11-14', '2026-11-15']);

        // One seat on the 14th, plenty on the 15th.
        $category->days()->where('event_date', '2026-11-14')->update(['quota' => 1]);
        $category->days()->where('event_date', '2026-11-15')->update(['quota' => 10]);

        $this->sell($user, $org, $event, $category, 2, ['2026-11-14'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sisa tiket untuk tanggal 2026-11-14 tidak mencukupi.');

        $this->sell($user, $org, $event, $category, 2, ['2026-11-15'])
            ->assertCreated();
    }

    /**
     * Who may staff the till: owner and `operator` both, a stranger not.
     *
     * The operator is the whole access decision here, and it is also the only
     * assertion that would fail under `org.admin` — asserting the owner alone
     * passes identically with the route narrowed.
     */
    public function test_operator_can_sell_but_another_orgs_member_cannot(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $outsider = User::factory()->create();

        $org = $this->orgWithTickets($owner);
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);
        $event = $this->event($org);
        $category = $this->category($event);

        $this->sell($owner, $org, $event, $category)->assertCreated();
        $this->sell($operator, $org, $event, $category)->assertCreated();
        $this->sell($outsider, $org, $event, $category)->assertStatus(403);
    }

    /**
     * A pass sold at the gate on day two admits day two, not all three.
     *
     * Compared against the ticket count of the order itself: asserting "someone
     * was checked in" would stay green with the day filter dropped, which is
     * exactly the bug — a holder burning tickets for days they have not
     * attended yet.
     */
    public function test_checking_in_a_pass_burns_only_todays_ticket(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->cashEvent($org);
        $days = ['2026-11-14', '2026-11-15', '2026-11-16'];
        $category = $this->category($event, 'pass', $days, ['price' => 100000]);

        $orderId = $this->sell($user, $org, $event, $category, 1, $days)
            ->assertCreated()
            ->json('data.order.id');

        $order = TicketOrder::findOrFail($orderId);
        $this->assertSame(3, $order->tickets()->count());

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/ticket-orders/{$orderId}/check-in")
            ->assertOk()
            ->assertJsonPath('data.checked_in', 1);

        $this->assertSame(1, $order->tickets()->where('is_used', true)->count());
        // And it is day two's row, not whichever came first.
        $this->assertSame(
            '2026-11-15',
            $order->tickets()->where('is_used', true)->first()->event_date->toDateString(),
        );

        // Nothing left for today — and the refusal names the days, because the
        // staff can act on the difference from "already all in".
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/ticket-orders/{$orderId}/check-in")
            ->assertStatus(422);
    }

    /**
     * A dateless order has no other day to be valid on, so one call admits all
     * of it. Compared with the pass above: a single rule covering both would
     * either strand plain tickets or burn a pass.
     */
    public function test_dateless_order_checks_in_every_ticket_at_once(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->cashEvent($org);
        $category = $this->category($event);

        $orderId = $this->sell($user, $org, $event, $category, 3)
            ->assertCreated()
            ->json('data.order.id');

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/ticket-orders/{$orderId}/check-in")
            ->assertOk()
            ->assertJsonPath('data.checked_in', 3);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/ticket-orders/{$orderId}/check-in")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Semua tiket pesanan ini sudah di-check-in.');
    }

    /** An unpaid gateway order has nobody to admit yet. */
    public function test_check_in_refuses_an_unpaid_order(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event);

        $orderId = $this->sell($user, $org, $event, $category)
            ->assertCreated()
            ->json('data.order.id');

        // Mock Midtrans settles it, so unpay it to stand in for a real gateway
        // order still waiting on its webhook.
        TicketOrder::findOrFail($orderId)->update(['status' => 'pending', 'paid_at' => null]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/ticket-orders/{$orderId}/check-in")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pesanan ini belum lunas.');
    }

    /** An order of another organization is not found, not merely refused. */
    public function test_check_in_cannot_reach_another_organizations_order(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $orderId = $this->sell($user, $org, $event, $this->category($event))
            ->assertCreated()
            ->json('data.order.id');

        $stranger = User::factory()->create();
        $otherOrg = $this->orgWithTickets($stranger);

        $this->actingAs($stranger, 'api')
            ->postJson("/api/v1/organizations/{$otherOrg->id}/ticket-orders/{$orderId}/check-in")
            ->assertStatus(404);
    }

    /**
     * The receipt has to say where the money went. Compared against a manual
     * order in the same event, because `onsite` falls through `isManual()` and
     * would otherwise print "Payment gateway" over cash.
     */
    public function test_receipt_labels_cash_apart_from_a_manual_transfer(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->cashEvent($org);
        $category = $this->category($event);

        $onsite = TicketOrder::findOrFail(
            $this->sell($user, $org, $event, $category)->assertCreated()->json('data.order.id'),
        );

        $manual = TicketOrder::findOrFail(
            $this->sell($user, $org, $event, $category)->assertCreated()->json('data.order.id'),
        );
        $manual->update(['payment_method' => 'manual']);

        // Read through the service's own method rather than the rendered PDF:
        // dompdf writes compressed streams, so grepping the bytes would pass or
        // fail for reasons that have nothing to do with the label.
        $label = new \ReflectionMethod(ParticipantDocumentService::class, 'methodLabel');
        $documents = app(ParticipantDocumentService::class);

        $this->assertSame('Tunai di loket', $label->invoke($documents, $onsite));
        $this->assertSame(
            'Transfer manual (diverifikasi penyelenggara)',
            $label->invoke($documents, $manual->fresh()),
        );
    }

    /** The plan gate, refusing the way an organizer surface does: 403 + feature. */
    public function test_plan_without_qr_tickets_cannot_sell(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user, ['qr_tickets' => 'false']);
        $event = $this->event($org);
        $category = $this->category($event);

        $this->sell($user, $org, $event, $category)
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'qr_tickets');
    }

    /**
     * A plan without `payment_gateway` cannot open an online payment, and the
     * box office of a gateway event is exactly that. The refusal comes from
     * `destinationFor()`, which is why the controller still calls it.
     *
     * Compared against the same plan on a manual-rail event, where the box
     * office takes cash and needs no entitlement at all.
     */
    public function test_plan_without_payment_gateway_cannot_sell_online_but_can_take_cash(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user, ['payment_gateway' => 'false']);

        $online = $this->event($org);
        $this->sell($user, $org, $online, $this->category($online))
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'payment_gateway');

        $cash = $this->cashEvent($org);
        $this->sell($user, $org, $cash, $this->category($cash))->assertCreated();
    }
}
