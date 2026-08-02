<?php

namespace Tests\Feature;

use App\Models\EventPlanOrder;
use App\Models\Plan;
use App\Models\User;
use App\Services\EventPlanOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Upgrading the plan an event (or an unspent credit) runs on.
 *
 * There is no downgrade, and the point of most of this file is that the absence
 * is structural: `PlanGate::planCovers()` refuses anything that is not a
 * superset, so a downgrade is not a route someone forgot to build — it is a
 * request the till turns away.
 */
class PlanUpgradeTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /** planWith() mints every plan at price 0; upgrades are all about price. */
    private function priced(Plan $plan, float $price): Plan
    {
        $plan->update(['price' => $price]);

        return $plan;
    }

    private function small(): Plan
    {
        return $this->priced($this->planWith([
            'online_registration' => 'true',
            'max_categories' => '1',
            'max_teams_per_category' => '32',
            'qr_tickets' => 'true',
        ], 'Kecil'), 150000);
    }

    private function big(): Plan
    {
        return $this->priced($this->planWith([
            'online_registration' => 'true',
            'max_categories' => '4',
            'max_teams_per_category' => '128',
            'qr_tickets' => 'true',
            'event_gallery' => 'true',
            'max_gallery_photos' => '15',
        ], 'Besar'), 350000);
    }

    /** Settle a bill the way the Midtrans webhook would. */
    private function settle(EventPlanOrder $order): void
    {
        app(EventPlanOrderService::class)->activate($order->fresh());
    }

    public function test_upgrade_is_billed_as_the_difference_only(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $small = $this->small();
        $credit = $this->creditFor($org, $small);
        $credit->update(['amount' => 150000]);

        $response = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$credit->id}/upgrade", [
                'plan_id' => $this->big()->id,
            ])
            ->assertCreated();

        // 350.000 − 150.000. Buying small then upgrading has to cost exactly
        // what buying big would have; a full-price top-up would total 500.000.
        $this->assertSame(200000.0, (float) $response->json('data.plan_order.amount'));
    }

    public function test_the_difference_is_measured_against_what_was_paid_not_the_old_catalogue_price(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $small = $this->small();

        // What `events:backfill-plan` leaves behind: a plan attached, but no
        // money ever taken for it. Charging only the catalogue gap would hand
        // over the bigger plan for 200.000 that nobody ever paid the first
        // 150.000 of.
        $free = $this->creditFor($org, $small);
        $free->update(['amount' => 0]);

        $response = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$free->id}/upgrade", [
                'plan_id' => $this->big()->id,
            ])
            ->assertCreated();

        $this->assertSame(350000.0, (float) $response->json('data.plan_order.amount'));
    }

    public function test_downgrade_and_sideways_moves_are_refused_while_the_upgrade_is_allowed(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $small = $this->small();
        $big = $this->big();

        // Comparative on purpose: the same request shape, three targets. An
        // assertion that only the upgrade works would pass just as happily if
        // every request were accepted.
        $onBig = $this->creditFor($org, $big);
        $onBig->update(['amount' => 350000]);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$onBig->id}/upgrade", ['plan_id' => $small->id])
            ->assertStatus(403);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$onBig->id}/upgrade", ['plan_id' => $big->id])
            ->assertStatus(403);

        $onSmall = $this->creditFor($org, $small);
        $onSmall->update(['amount' => 150000]);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$onSmall->id}/upgrade", ['plan_id' => $big->id])
            ->assertCreated();
    }

    public function test_a_dearer_plan_that_drops_a_feature_is_not_an_upgrade(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        $current = $this->priced($this->planWith([
            'qr_tickets' => 'true',
            'event_gallery' => 'true',
            'max_categories' => '4',
        ], 'Sekarang'), 200000);

        // Dearer, roomier on the number — and quietly without the gallery. A
        // price comparison waves this through and strips a feature the event may
        // already be using; planCovers() is what catches it.
        $trap = $this->priced($this->planWith([
            'qr_tickets' => 'true',
            'max_categories' => '10',
        ], 'Jebakan'), 900000);

        $order = $this->creditFor($org, $current);
        $order->update(['amount' => 200000]);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$order->id}/upgrade", ['plan_id' => $trap->id])
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'plan_not_superset');

        // And it is absent from what the client is offered, so the refusal is
        // never a surprise at the till.
        $offered = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/plan-orders/{$order->id}/upgrade-options")
            ->assertOk()
            ->json('data');

        $this->assertNotContains($trap->id, array_column(array_column($offered, 'plan'), 'id'));
    }

    public function test_unlimited_may_not_be_traded_for_a_number(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        $unlimited = $this->priced($this->planWith(['max_teams_per_category' => '-1'], 'Unlimited'), 100000);
        $capped = $this->priced($this->planWith(['max_teams_per_category' => '9999'], 'Besar terbatas'), 800000);

        $order = $this->creditFor($org, $unlimited);
        $order->update(['amount' => 100000]);

        // -1 is the top of the scale, not below 1 — a naive `<` comparison reads
        // it as the smallest cap there is and calls this an upgrade.
        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$order->id}/upgrade", ['plan_id' => $capped->id])
            ->assertStatus(403);
    }

    public function test_the_upgraded_order_stops_counting_as_a_credit(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $small = $this->small();
        $credit = $this->creditFor($org, $small);
        $credit->update(['amount' => 150000]);

        $this->assertCount(1, $org->planOrders()->unconsumed()->get());

        $upgradeId = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$credit->id}/upgrade", [
                'plan_id' => $this->big()->id,
            ])
            ->assertCreated()
            ->json('data.plan_order.id');

        $this->settle(EventPlanOrder::findOrFail($upgradeId));

        // The count is the assertion. Two paid orders now exist, but only one
        // entitlement was bought — the organizer paid the difference, so letting
        // the old row back into the pool would be giving away an event.
        $credits = $org->planOrders()->unconsumed()->get();
        $this->assertCount(1, $credits);
        $this->assertSame($upgradeId, $credits->first()->id);
    }

    public function test_an_upgraded_credit_creates_an_event_on_the_new_plan(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $small = $this->small();
        $big = $this->big();
        $credit = $this->creditFor($org, $small);
        $credit->update(['amount' => 150000]);

        $upgradeId = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$credit->id}/upgrade", ['plan_id' => $big->id])
            ->json('data.plan_order.id');
        $this->settle(EventPlanOrder::findOrFail($upgradeId));

        // Two categories: refused under the old plan's cap of 1, which is what
        // makes this prove the entitlement moved rather than just the label.
        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", [
                'name' => 'Piala Upgrade',
                'sport_type' => 'futsal',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-10',
                'categories' => [
                    ['name' => 'Umum', 'tournament_format' => 'league'],
                    ['name' => 'U-17', 'tournament_format' => 'league'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.plan.slug', $big->slug);
    }

    public function test_upgrading_a_running_event_moves_it_and_unlocks_the_feature_it_lacked(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $small = $this->small();
        $event = $this->eventOn($org, $small);
        $order = $org->planOrders()->where('event_id', $event->id)->firstOrFail();
        $order->update(['amount' => 150000]);

        $photos = ['photos' => [['photo_url' => 'https://example.test/a.jpg']]];

        // Refused before, allowed after — the same request against the same
        // event. Asserting only the second half would pass even if the plan had
        // never been the thing standing in the way.
        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/photos", $photos)
            ->assertStatus(403);

        $upgradeId = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$order->id}/upgrade", [
                'plan_id' => $this->big()->id,
            ])
            ->assertCreated()
            ->json('data.plan_order.id');

        $this->settle(EventPlanOrder::findOrFail($upgradeId));

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/photos", $photos)
            ->assertCreated();

        // The event has exactly one order against it, and it is the new one.
        $this->assertSame(1, EventPlanOrder::where('event_id', $event->id)->count());
        $this->assertSame($upgradeId, EventPlanOrder::where('event_id', $event->id)->value('id'));
    }

    public function test_settling_the_same_upgrade_twice_changes_nothing(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org, $this->small());
        $order = $org->planOrders()->where('event_id', $event->id)->firstOrFail();
        $order->update(['amount' => 150000]);

        $upgradeId = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$order->id}/upgrade", [
                'plan_id' => $this->big()->id,
            ])
            ->json('data.plan_order.id');

        $upgrade = EventPlanOrder::findOrFail($upgradeId);
        $this->settle($upgrade);
        $receipt = $upgrade->fresh()->receipt_number;

        // Midtrans re-delivers. A second run must not issue a second receipt,
        // nor release the event back to the retired order.
        $this->settle($upgrade);

        $this->assertSame($receipt, $upgrade->fresh()->receipt_number);
        $this->assertSame(1, EventPlanOrder::where('event_id', $event->id)->count());
        $this->assertCount(0, $org->planOrders()->unconsumed()->get());
    }

    public function test_the_same_order_cannot_be_upgraded_twice(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $credit = $this->creditFor($org, $this->small());
        $credit->update(['amount' => 150000]);
        $big = $this->big();

        $first = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$credit->id}/upgrade", ['plan_id' => $big->id])
            ->json('data.plan_order.id');
        $this->settle(EventPlanOrder::findOrFail($first));

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$credit->id}/upgrade", ['plan_id' => $big->id])
            ->assertStatus(403);
    }

    public function test_an_unpaid_upgrade_is_reopened_rather_than_billed_again(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $credit = $this->creditFor($org, $this->small());
        $credit->update(['amount' => 150000]);
        $big = $this->big();

        $url = "/api/v1/organizations/{$org->id}/plan-orders/{$credit->id}/upgrade";
        $first = $this->actingAs($owner, 'api')->postJson($url, ['plan_id' => $big->id])->json('data.plan_order.id');
        EventPlanOrder::whereKey($first)->update(['status' => 'past_due']);

        $second = $this->actingAs($owner, 'api')->postJson($url, ['plan_id' => $big->id])->json('data.plan_order.id');

        // One outstanding bill, not two — otherwise both could be settled and
        // the organizer would pay the difference twice for one move.
        $this->assertSame($first, $second);
        $this->assertSame(1, EventPlanOrder::where('upgrade_of_id', $credit->id)->count());
    }

    /**
     * The synthetic plans above are convenient and they lie: none of them
     * carries `platform_fee_percent`, the one numeric key in the real catalogue
     * where a *smaller* number is the better deal (Starter 3%, Pro 2%,
     * Professional 1%). Read on the capacity scale that looks like a loss, and
     * planCovers() refused Starter → Pro — the single most obvious upgrade there
     * is. Twelve green tests said nothing about it. So this one asks the
     * catalogue the migration actually seeds.
     */
    public function test_the_real_catalogue_upgrades_in_the_order_its_prices_suggest(): void
    {
        $starter = Plan::where('slug', 'starter')->firstOrFail();
        $pro = Plan::where('slug', 'pro')->firstOrFail();
        $professional = Plan::where('slug', 'professional')->firstOrFail();

        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $order = $this->creditFor($org, $starter);
        $order->update(['amount' => $starter->price]);

        $offered = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/plan-orders/{$order->id}/upgrade-options")
            ->assertOk()
            ->json('data');

        $this->assertSame(
            [$pro->id, $professional->id],
            array_column(array_column($offered, 'plan'), 'id'),
        );
        $this->assertSame(
            [(float) $pro->price - (float) $starter->price, (float) $professional->price - (float) $starter->price],
            array_map(fn ($o) => (float) $o['price_difference'], $offered),
        );

        // And the other direction stays shut on the real plans too.
        $onTop = $this->creditFor($org, $professional);
        $onTop->update(['amount' => $professional->price]);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$onTop->id}/upgrade", ['plan_id' => $pro->id])
            ->assertStatus(403);
    }

    /**
     * Two upgrades in a row must still total the catalogue price.
     *
     * Pricing each step against the order's own `amount` passes every
     * single-step test in this file and quietly overcharges the moment someone
     * climbs twice: after Starter → Pro the holder carries only the 200.000
     * top-up, so Professional would be billed at 800.000 − 200.000 and the
     * organizer pays 950.000 for a plan sold at 800.000. Caught on the running
     * stack, not by any of the twelve tests written before it.
     */
    public function test_climbing_twice_costs_the_same_as_buying_the_top_outright(): void
    {
        $starter = Plan::where('slug', 'starter')->firstOrFail();
        $pro = Plan::where('slug', 'pro')->firstOrFail();
        $professional = Plan::where('slug', 'professional')->firstOrFail();

        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $order = $this->creditFor($org, $starter);
        $order->update(['amount' => $starter->price]);

        $url = fn (string $id) => "/api/v1/organizations/{$org->id}/plan-orders/{$id}/upgrade";

        $first = $this->actingAs($owner, 'api')->postJson($url($order->id), ['plan_id' => $pro->id])
            ->assertCreated()->json('data.plan_order');
        $this->settle(EventPlanOrder::findOrFail($first['id']));

        $second = $this->actingAs($owner, 'api')->postJson($url($first['id']), ['plan_id' => $professional->id])
            ->assertCreated()->json('data.plan_order');

        $total = (float) $order->amount + (float) $first['amount'] + (float) $second['amount'];

        $this->assertSame((float) $professional->price, $total);
    }

    public function test_operator_cannot_upgrade(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $credit = $this->creditFor($org, $this->small());
        $credit->update(['amount' => 150000]);

        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $this->actingAs($operator, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$credit->id}/upgrade", [
                'plan_id' => $this->big()->id,
            ])
            ->assertStatus(403);
    }
}
