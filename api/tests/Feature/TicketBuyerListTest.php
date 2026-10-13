<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\TicketCategory;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The organizer's buyer list: `GET events/{event}/ticket-orders`.
 *
 * Filtering and paging live on the server, so every case here is written as a
 * *comparison* — the page it replaced filtered in the browser, and a list that
 * only ever returns rows cannot show that a filter runs at all:
 *
 * - asserting that a filter returns the matching order says nothing about
 *   whether the non-matching ones were excluded;
 * - asserting that a page holds two rows says nothing about whether the summary
 *   cards above it count the page or the whole filtered set;
 * - asserting that a day filter finds a Saturday buyer says nothing about
 *   whether a dateless order — which claims no day at all — leaked in with it.
 */
class TicketBuyerListTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

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

    /**
     * @param  list<string>  $dates
     */
    private function category(Event $event, string $mode, array $dates = [], array $attrs = []): TicketCategory
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

    /**
     * An order as the buyer flow would have left it, settled where asked.
     *
     * Written through the service rather than the HTTP endpoint: these cases are
     * about reading the list, and the purchase rules have their own suite.
     *
     * @param  list<string>  $dates
     */
    private function order(
        TicketCategory $category,
        string $buyer,
        string $status = 'paid',
        array $dates = [],
        int $seats = 1,
    ): TicketOrder {
        /** @var TicketService $service */
        $service = app(TicketService::class);

        $order = $service->purchase(
            category: $category,
            buyer: [
                'quantity' => $category->day_mode === 'per_day' ? $seats * max(1, count($dates)) : $seats,
                'buyer_name' => $buyer,
                'buyer_email' => strtolower(str_replace(' ', '.', $buyer)).'@test.com',
                'buyer_phone' => '0812'.random_int(1000000, 9999999),
            ],
            holderNames: [],
            orderId: 'MID-'.uniqid(),
            userId: null,
            dates: $dates,
            seats: $seats,
        );

        $order->update(['status' => $status]);

        return $order->fresh();
    }

    private function list(User $user, Organization $org, Event $event, array $query = []): TestResponse
    {
        return $this->actingAs($user, 'api')->getJson(
            "/api/v1/organizations/{$org->id}/events/{$event->id}/ticket-orders?".http_build_query($query),
        );
    }

    /**
     * Status, category, and search, each proved by what it leaves out.
     *
     * One request per filter against the same four orders, so a clause that
     * never runs is visible: an unfiltered list returns all four, and every
     * assertion below is a smaller number than that.
     */
    public function test_each_filter_excludes_the_rows_it_should(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);

        $vip = $this->category($event, 'none', [], ['name' => 'VIP']);
        $reguler = $this->category($event, 'none', [], ['name' => 'Reguler']);

        $this->order($vip, 'Budi Santoso');
        $this->order($reguler, 'Ani Wijaya');
        $this->order($reguler, 'Citra Dewi', 'pending');
        $this->order($reguler, 'Dedi Kurnia', 'cancelled');

        $all = $this->list($user, $org, $event)->assertOk();
        $this->assertSame(4, $all->json('data.meta.total'));

        // Status: three of the four are not paid-or-pending.
        $this->assertSame(2, $this->list($user, $org, $event, ['status' => 'paid'])->json('data.meta.total'));
        $this->assertSame(1, $this->list($user, $org, $event, ['status' => 'pending'])->json('data.meta.total'));

        // Category: Reguler holds three of the four.
        $byCategory = $this->list($user, $org, $event, ['category_id' => $reguler->id])->assertOk();
        $this->assertSame(3, $byCategory->json('data.meta.total'));
        $this->assertNotContains('Budi Santoso', $byCategory->json('data.items.*.buyer_name'));

        // Search, case-insensitively and on the email too — plain LIKE is
        // case-sensitive on Postgres, so "BUDI" is the case that proves it.
        $this->assertSame(1, $this->list($user, $org, $event, ['q' => 'BUDI'])->json('data.meta.total'));
        $this->assertSame(1, $this->list($user, $org, $event, ['q' => 'ani.wijaya@'])->json('data.meta.total'));
        $this->assertSame(0, $this->list($user, $org, $event, ['q' => 'tidak ada'])->json('data.meta.total'));

        // And they compose: paid + Reguler is one row, not three.
        $both = $this->list($user, $org, $event, ['status' => 'paid', 'category_id' => $reguler->id])->assertOk();
        $this->assertSame(1, $both->json('data.meta.total'));
        $this->assertSame('Ani Wijaya', $both->json('data.items.0.buyer_name'));
    }

    /**
     * The day filter is about attendance, and a dateless order claims no day.
     *
     * Asserting that the Saturday buyer comes back would stay green with the
     * filter not running at all — the `none` order is the row that can only be
     * absent if it does.
     */
    public function test_day_filter_matches_attendance_and_drops_dateless_orders(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $days = ['2026-11-14', '2026-11-15', '2026-11-16'];

        $plain = $this->category($event, 'none', [], ['name' => 'Seluruh event']);
        $perDay = $this->category($event, 'per_day', $days, ['name' => 'Harian']);

        $this->order($plain, 'Tanpa Tanggal');
        $this->order($perDay, 'Hari Sabtu', 'paid', ['2026-11-14']);
        $this->order($perDay, 'Hari Senin', 'paid', ['2026-11-16']);

        $saturday = $this->list($user, $org, $event, ['from' => '2026-11-14', 'to' => '2026-11-14'])->assertOk();
        $this->assertSame(['Hari Sabtu'], $saturday->json('data.items.*.buyer_name'));

        // An open-ended range still excludes the dateless order: it has no
        // `event_date` to fall inside any range.
        $fromSunday = $this->list($user, $org, $event, ['from' => '2026-11-15'])->assertOk();
        $this->assertSame(['Hari Senin'], $fromSunday->json('data.items.*.buyer_name'));

        // Unfiltered, all three are there — so the two above dropped rows the
        // endpoint can plainly see.
        $this->assertSame(3, $this->list($user, $org, $event)->json('data.meta.total'));
    }

    /**
     * A pass holder attends every day it covers, and the order has one ticket
     * row per day — so it matches whichever single day is asked for.
     */
    public function test_a_pass_order_matches_every_day_it_covers(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $days = ['2026-11-14', '2026-11-15', '2026-11-16'];

        $pass = $this->category($event, 'pass', $days, ['name' => 'Terusan', 'price' => 100000]);
        $this->order($pass, 'Pemegang Terusan', 'paid', $days);

        foreach ($days as $day) {
            $this->assertSame(
                1,
                $this->list($user, $org, $event, ['from' => $day, 'to' => $day])->json('data.meta.total'),
                "pass should match {$day}",
            );
        }

        // And not a day outside its range.
        $this->assertSame(0, $this->list($user, $org, $event, ['from' => '2026-11-17'])->json('data.meta.total'));
    }

    /**
     * The summary cards cover the whole filtered set, not the page.
     *
     * Asserting the totals on page 1 alone would stay green with a per-page sum,
     * which is exactly the bug: paging through a sold-out event would make the
     * revenue card count down.
     */
    public function test_summary_covers_the_whole_filtered_set_not_the_page(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event, 'none', [], ['name' => 'Reguler']);

        foreach (range(1, 3) as $i) {
            $this->order($category, "Pembeli {$i}");
        }

        $first = $this->list($user, $org, $event, ['per_page' => 2])->assertOk();
        $second = $this->list($user, $org, $event, ['per_page' => 2, 'page' => 2])->assertOk();

        $this->assertCount(2, $first->json('data.items'));
        $this->assertCount(1, $second->json('data.items'));
        $this->assertSame(2, $first->json('data.meta.last_page'));
        $this->assertSame(3, $first->json('data.meta.total'));

        // The same three paid orders on both pages — this is the comparison
        // that a per-page sum fails.
        foreach ([$first, $second] as $page) {
            $this->assertSame(3, $page->json('data.summary.paid_orders'));
            $this->assertSame(3, $page->json('data.summary.sold_tickets'));
            $this->assertEqualsWithDelta(120_000.0, (float) $page->json('data.summary.revenue'), 0.0001);
        }
    }

    /**
     * Sales are paid-only, and tickets are counted as issued rows.
     *
     * Two things one assertion cannot separate: a pending order must not reach
     * the revenue card at all, and `quantity` must not be what "tiket terjual"
     * means — a two-seat pass over three days is 2 paid units and 6 QRs, and the
     * ticket page's own card counts the rows.
     */
    public function test_summary_counts_paid_ticket_rows_not_quantity(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $days = ['2026-11-14', '2026-11-15', '2026-11-16'];

        $pass = $this->category($event, 'pass', $days, ['name' => 'Terusan', 'price' => 100000]);
        $paid = $this->order($pass, 'Lunas', 'paid', $days, seats: 2);
        $this->order($pass, 'Belum Bayar', 'pending', $days, seats: 2);

        $this->assertSame(2, $paid->quantity);
        $this->assertSame(6, $paid->tickets()->count());

        $res = $this->list($user, $org, $event)->assertOk();

        // Both orders are listed — this is a buyer list, not a sales list.
        $this->assertSame(2, $res->json('data.meta.total'));
        // But only one of them is a sale, and it is worth six QRs.
        $this->assertSame(1, $res->json('data.summary.paid_orders'));
        $this->assertSame(6, $res->json('data.summary.sold_tickets'));
        $this->assertEqualsWithDelta(200_000.0, (float) $res->json('data.summary.revenue'), 0.0001);
    }

    /**
     * Status is the one filter the cards ignore, so the "Dibatalkan" tab can
     * still say what the event took. Compared against the category filter,
     * which they do follow — otherwise "status is ignored" is indistinguishable
     * from "every filter is ignored".
     */
    public function test_cards_ignore_status_but_follow_the_other_filters(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);

        $vip = $this->category($event, 'none', [], ['name' => 'VIP', 'price' => 100000]);
        $reguler = $this->category($event, 'none', [], ['name' => 'Reguler']);

        $this->order($vip, 'Vip Lunas');
        $this->order($reguler, 'Reguler Lunas');
        $this->order($reguler, 'Reguler Batal', 'cancelled');

        $cancelled = $this->list($user, $org, $event, ['status' => 'cancelled'])->assertOk();
        $this->assertSame(1, $cancelled->json('data.meta.total'));
        // Still the event's two paid orders, 140k, beside a one-row list.
        $this->assertSame(2, $cancelled->json('data.summary.paid_orders'));
        $this->assertEqualsWithDelta(140_000.0, (float) $cancelled->json('data.summary.revenue'), 0.0001);

        $byCategory = $this->list($user, $org, $event, ['category_id' => $reguler->id])->assertOk();
        $this->assertSame(1, $byCategory->json('data.summary.paid_orders'));
        $this->assertEqualsWithDelta(40_000.0, (float) $byCategory->json('data.summary.revenue'), 0.0001);
    }

    /**
     * The dropdown's options are the event's categories, not the ones with
     * sales — filtering by an empty category is how an organizer finds out it
     * has sold nothing.
     */
    public function test_category_options_include_categories_with_no_sales(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);

        $sold = $this->category($event, 'none', [], ['name' => 'Reguler']);
        $this->category($event, 'none', [], ['name' => 'VIP']);
        $this->order($sold, 'Budi');

        $res = $this->list($user, $org, $event)->assertOk();

        $this->assertSame(['Reguler', 'VIP'], $res->json('data.categories.*.name'));
        $this->assertSame(1, $res->json('data.meta.total'));
    }

    /** A gate operator must not read buyer contacts — unchanged by any of this. */
    public function test_operator_cannot_read_the_buyer_list(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);

        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $this->list($operator, $org, $event)->assertForbidden();
        $this->list($user, $org, $event)->assertOk();
    }

    /** A bad range is rejected rather than silently returning everything. */
    public function test_an_inverted_date_range_is_rejected(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);

        $this->list($user, $org, $event, ['from' => '2026-11-16', 'to' => '2026-11-14'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');

        $this->list($user, $org, $event, ['status' => 'entah'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }
}
