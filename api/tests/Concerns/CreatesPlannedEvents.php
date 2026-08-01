<?php

namespace Tests\Concerns;

use App\Models\Event;
use App\Models\EventPlanOrder;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;

/**
 * Building blocks for tests that need an event with entitlements.
 *
 * Entitlements hang off `events.plan_id`, so a test that exercises any gate has
 * to say which plan its event runs on. Two shapes are useful and they are not
 * interchangeable:
 *
 *  - `eventOn()` — the event already exists and is already paid for. Use this
 *    whenever the plan is scenery rather than the thing under test.
 *  - `creditFor()` — an unspent credit, for tests that go through
 *    `POST /events` and want the real claim path.
 *
 * The slugs are randomised throughout: the catalogue migration seeds
 * starter/pro/professional into every test database, so fixed slugs collide.
 */
trait CreatesPlannedEvents
{
    /**
     * The plan every event in this test runs on, unless told otherwise.
     *
     * Assign it when the plan *is* the subject — a test measuring the platform
     * fee has to put its own percentage on the event, not the permissive
     * default. Left alone it lazily becomes fullPlan().
     */
    protected ?Plan $testPlan = null;

    /**
     * The id of this test's plan, for `->events()->create(['plan_id' => ...])`.
     *
     * For the many tests where entitlements are scenery — schedules, brackets,
     * standings, discipline — and the only thing that matters is that no gate
     * fires.
     */
    protected function planId(): string
    {
        return ($this->testPlan ??= $this->fullPlan())->id;
    }

    /**
     * A plan carrying exactly the features named. Nothing is granted by
     * default — an absent key means the gate refuses, which is what production
     * does too.
     *
     * @param  array<string, string>  $features
     */
    protected function planWith(array $features = [], string $name = 'Test'): Plan
    {
        $plan = Plan::create([
            'name' => $name,
            'slug' => 'test-'.uniqid(),
            'price' => 0,
        ]);

        foreach ($features as $key => $value) {
            $plan->features()->create(['feature_key' => $key, 'value' => $value]);
        }

        return $plan;
    }

    /**
     * A plan that grants everything the catalogue's top tier does. For the many
     * tests where the plan is scenery — schedules, brackets, standings — and
     * spelling out features would only be noise.
     */
    protected function fullPlan(): Plan
    {
        return $this->planWith([
            'online_registration' => 'true',
            'max_categories' => '-1',
            'max_teams_per_category' => '-1',
            'payment_gateway' => 'true',
            'platform_fee_percent' => '0',
            'qr_tickets' => 'true',
            'export_data' => 'true',
            'sponsor_logos' => 'true',
            'organizer_profile' => 'true',
            'certificate_generator' => 'true',
            'certificate_email' => 'true',
            'event_gallery' => 'true',
            'max_gallery_photos' => '-1',
        ]);
    }

    protected function orgFor(User $owner, string $name = 'Org'): Organization
    {
        return Organization::create([
            'name' => $name,
            'slug' => 'org-'.uniqid(),
            'owner_id' => $owner->id,
            'contact_email' => 'org@example.test',
        ]);
    }

    /**
     * A paid, unspent credit — what `POST /events` claims.
     */
    protected function creditFor(Organization $org, ?Plan $plan = null): EventPlanOrder
    {
        return $org->planOrders()->create([
            'plan_id' => ($plan ?? $this->fullPlan())->id,
            'amount' => 0,
            'status' => 'paid',
            'paid_at' => now(),
            'payment_method' => 'gateway',
        ]);
    }

    /**
     * An event already running on a plan, with the matching consumed credit so
     * the data looks like it came through the real flow.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function eventOn(Organization $org, ?Plan $plan = null, array $attrs = []): Event
    {
        $plan ??= $this->fullPlan();

        $event = $org->events()->create([
            'plan_id' => $plan->id,
            'name' => 'Cup',
            'slug' => 'cup-'.uniqid(),
            'sport_type' => 'football',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-10',
            ...$attrs,
        ]);

        $org->planOrders()->create([
            'plan_id' => $plan->id,
            'event_id' => $event->id,
            'consumed_at' => now(),
            'amount' => 0,
            'status' => 'paid',
            'paid_at' => now(),
            'payment_method' => 'gateway',
        ]);

        return $event;
    }
}
