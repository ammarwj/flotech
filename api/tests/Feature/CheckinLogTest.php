<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The gate log: `GET events/{event}/checkins`.
 *
 * Split out of `ticket-report`, where it used to ride along as a fixed 20-row
 * `recent_checkins` tail. Every case here is a *comparison*, because a list
 * endpoint that returns rows proves nothing on its own:
 *
 * - asserting that an admitted holder is listed says nothing about whether the
 *   ones who never came through the gate were left out;
 * - asserting that page 1 holds two rows says nothing about whether the twenty
 *   first-row ceiling the report carried is really gone;
 * - asserting that an unpaid ticket is absent needs a paid one beside it, or
 *   it stays green with no scope at all.
 */
class CheckinLogTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-morning WIB on day two of the window below: `checkInOrder()`
        // admits today's rows only, so a floating clock would move which day
        // these cases are about.
        Carbon::setTestNow('2026-11-15 03:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function orgWithTickets(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);
        foreach (['qr_tickets' => 'true', 'payment_gateway' => 'true'] as $key => $value) {
            $plan->features()->create(['feature_key' => $key, 'value' => $value]);
        }

        $this->testPlan = $plan;

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    /** A three-day event: 14–16 November. */
    private function event(Organization $org): Event
    {
        return $org->events()->create([
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
            'price' => 40000,
            'is_active' => true,
        ]);

        foreach ($dates as $date) {
            $category->days()->create(['event_date' => $date]);
        }

        return $category->fresh()->load('days');
    }

    /** @param  list<string>  $dates */
    private function order(
        TicketCategory $category,
        string $holder,
        string $status = 'paid',
        array $dates = [],
    ): TicketOrder {
        /** @var TicketService $service */
        $service = app(TicketService::class);

        $order = $service->purchase(
            category: $category,
            buyer: [
                'quantity' => $category->day_mode === 'per_day' ? max(1, count($dates)) : 1,
                'buyer_name' => $holder,
                'buyer_email' => strtolower(str_replace(' ', '.', $holder)).'@test.com',
                'buyer_phone' => '0812'.random_int(1000000, 9999999),
            ],
            holderNames: [$holder],
            orderId: 'MID-'.uniqid(),
            userId: null,
            dates: $dates,
            seats: 1,
        );

        $order->update(['status' => $status]);

        return $order->fresh();
    }

    /** Admit every ticket of an order, whatever day it is for. */
    private function admit(TicketOrder $order): void
    {
        foreach ($order->tickets as $ticket) {
            $ticket->update(['is_used' => true, 'used_at' => Carbon::now()]);
        }
    }

    private function log(User $user, Organization $org, Event $event, array $query = []): TestResponse
    {
        return $this->actingAs($user, 'api')->getJson(
            "/api/v1/organizations/{$org->id}/events/{$event->id}/checkins?".http_build_query($query),
        );
    }

    /**
     * The log holds the people who came through the gate, and only them.
     *
     * Three orders, one of each shape: admitted, paid but never scanned, and
     * admitted on an unpaid order. The last is the one that needs the first
     * beside it — `checkIn()` refuses an unpaid QR outright, so a row like it
     * could only come from data written behind the gate, and a list with no
     * scope at all would happily carry it.
     */
    public function test_log_lists_admitted_paid_tickets_only(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event);

        $this->admit($this->order($category, 'Sudah Masuk'));
        $this->order($category, 'Belum Datang');
        $this->admit($this->order($category, 'Belum Bayar', 'pending'));

        // All three exist as used/unused ticket rows, so the filtering is the
        // endpoint's and not the fixture's.
        $this->assertSame(3, $event->tickets()->count());
        $this->assertSame(2, $event->tickets()->where('is_used', true)->count());

        $res = $this->log($user, $org, $event)->assertOk();

        $this->assertSame(1, $res->json('data.meta.total'));
        $this->assertSame(['Sudah Masuk'], $res->json('data.items.*.holder_name'));
    }

    /**
     * It pages, and the page is the whole truth about how many there are.
     *
     * The ceiling it replaced was 20 rows with no count beside them, so the
     * comparison that matters is page 2 existing *and* `meta.total` naming
     * everyone — asserting a full first page alone stays green with a `limit`.
     */
    public function test_log_pages_instead_of_stopping_at_a_fixed_tail(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event);

        foreach (range(1, 25) as $i) {
            $this->admit($this->order($category, sprintf('Penonton %02d', $i)));
        }

        $first = $this->log($user, $org, $event)->assertOk();
        $this->assertCount(20, $first->json('data.items'));
        $this->assertSame(25, $first->json('data.meta.total'));
        $this->assertSame(2, $first->json('data.meta.last_page'));

        $second = $this->log($user, $org, $event, ['page' => 2])->assertOk();
        $this->assertCount(5, $second->json('data.items'));

        // And the two pages are disjoint — a page parameter that is read but
        // not applied returns the same twenty rows twice.
        $this->assertEmpty(array_intersect(
            $first->json('data.items.*.id'),
            $second->json('data.items.*.id'),
        ));
    }

    /**
     * Search and the day filter, each proved by what it leaves out.
     *
     * The day asked for is the *attendance* day, not the moment of the scan:
     * all three holders below were admitted at the same instant, so a filter
     * reading `used_at` would return all of them for any day — which is exactly
     * what asserting "Sabtu comes back" would miss.
     */
    public function test_search_and_day_filter_each_exclude_the_rows_they_should(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $days = ['2026-11-14', '2026-11-15', '2026-11-16'];

        $plain = $this->category($event, 'none', [], ['name' => 'Seluruh event']);
        $perDay = $this->category($event, 'per_day', $days, ['name' => 'Harian']);

        $this->admit($this->order($plain, 'Tanpa Tanggal'));
        $this->admit($this->order($perDay, 'Hari Sabtu', 'paid', ['2026-11-14']));
        $this->admit($this->order($perDay, 'Hari Senin', 'paid', ['2026-11-16']));

        $this->assertSame(3, $this->log($user, $org, $event)->json('data.meta.total'));

        // Case-insensitively: plain LIKE is case-sensitive on Postgres, so the
        // shouted term is the one that proves Search::anyColumn() is in play.
        $this->assertSame(
            ['Hari Sabtu'],
            $this->log($user, $org, $event, ['q' => 'SABTU'])->json('data.items.*.holder_name'),
        );
        $this->assertSame(0, $this->log($user, $org, $event, ['q' => 'tidak ada'])->json('data.meta.total'));

        // The day: one of three, and the dateless holder claims no day at all.
        $saturday = $this->log($user, $org, $event, ['date' => '2026-11-14'])->assertOk();
        $this->assertSame(['Hari Sabtu'], $saturday->json('data.items.*.holder_name'));
        $this->assertSame(0, $this->log($user, $org, $event, ['date' => '2026-11-15'])->json('data.meta.total'));
    }

    /**
     * Newest first — the gate reads the top of this list to see who just came
     * in. Compared across three rows admitted minutes apart, because a list in
     * insertion order puts the same row first whenever the fixture happens to
     * create it first.
     */
    public function test_log_is_newest_first(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event);

        $orders = [
            'Pertama' => $this->order($category, 'Pertama'),
            'Kedua' => $this->order($category, 'Kedua'),
            'Ketiga' => $this->order($category, 'Ketiga'),
        ];

        // Admitted in the opposite order to their creation, so insertion order
        // and scan order disagree.
        foreach (['Ketiga', 'Pertama', 'Kedua'] as $i => $name) {
            Ticket::where('order_id', $orders[$name]->id)->update([
                'is_used' => true,
                'used_at' => Carbon::now()->addMinutes($i),
            ]);
        }

        $this->assertSame(
            ['Kedua', 'Pertama', 'Ketiga'],
            $this->log($user, $org, $event)->assertOk()->json('data.items.*.holder_name'),
        );
    }

    /**
     * Scoped to the event and to the organization.
     *
     * A second event of the same org holds the first guard: without it the log
     * is the org's, and an organizer running two tournaments at once sees the
     * other one's gate. A foreign org holds the second.
     */
    public function test_log_is_scoped_to_its_event_and_org(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);

        $event = $this->event($org);
        $other = $this->event($org);

        $this->admit($this->order($this->category($event), 'Event Ini'));
        $this->admit($this->order($this->category($other), 'Event Lain'));

        $this->assertSame(
            ['Event Ini'],
            $this->log($user, $org, $event)->assertOk()->json('data.items.*.holder_name'),
        );

        $stranger = User::factory()->create();
        $this->log($stranger, $org, $event)->assertForbidden();
    }
}
