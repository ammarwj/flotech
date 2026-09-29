<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Held funds become withdrawable once the next 01:00 in the organizer's zone
 * has passed — the clock, and nothing else. The event's own status no longer
 * releases anything (what an ended event does now is waive the withdrawal
 * minimum; that lives in WithdrawalTest).
 *
 * Every test here pins the clock BEFORE the sale, because `available_at` is
 * derived from the moment the credit is written.
 */
class WalletReleaseTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function orgWithPlan(User $owner, array $features = []): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);

        // See WalletTest: PaymentRails gates online payment on this entitlement,
        // and every seeded plan grants it.
        $features = ['payment_gateway' => 'true'] + $features;

        foreach ($features as $key => $value) {
            $plan->features()->create(['feature_key' => $key, 'value' => $value]);
        }

        // Events in this test run on this plan — planId() is what puts it there.
        $this->testPlan = $plan;

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    /**
     * An org with 100.000 pending from one paid order, bought at whatever the
     * clock currently says. The event's dates are deliberately irrelevant now.
     */
    private function seedPendingIncome(User $user): array
    {
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true']);
        $event = $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'tournament_format' => 'league', 'status' => 'open',
            'start_date' => '2026-08-01', 'end_date' => '2026-12-20',
        ]);
        $category = $event->ticketCategories()->create(['name' => 'Reguler', 'price' => 50000, 'is_active' => true]);

        $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
            'ticket_category_id' => $category->id,
            'quantity' => 2,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
            'payment_channel' => 'va',
        ])->assertCreated();

        return [$org, $event];
    }

    private function sweepAt(string $utc): void
    {
        Carbon::setTestNow($utc);
        $this->artisan('wallet:release')->assertSuccessful();
    }

    private function assertBalances(Organization $org, string $pending, string $available, string $message = ''): void
    {
        $wallet = $org->wallet()->first();

        $this->assertSame($pending, (string) $wallet->balance_pending, $message);
        $this->assertSame($available, (string) $wallet->balance_available, $message);
    }

    /**
     * The rule, stated as a comparison on one credit: a sweep at 00:00 WIB the
     * next morning is too early, the sweep an hour later is not. Asserting only
     * the second half would pass even if the boundary were midnight.
     */
    public function test_a_morning_credit_clears_at_the_next_0100_and_not_before(): void
    {
        Carbon::setTestNow('2026-08-02 03:00:00'); // 10:00 WIB
        [$org] = $this->seedPendingIncome(User::factory()->create());

        // 2026-08-02 17:00 UTC = 2026-08-03 00:00 WIB. One hour short.
        $this->sweepAt('2026-08-02 17:00:00');
        $this->assertBalances($org, '100000.00', '0.00', 'Midnight WIB is not the boundary — 01:00 is.');

        // 2026-08-02 18:00 UTC = 2026-08-03 01:00 WIB.
        $this->sweepAt('2026-08-02 18:00:00');
        $this->assertBalances($org, '0.00', '100000.00');
    }

    /**
     * No minimum-one-night rule: the boundary is literally the next 01:00, so
     * money that arrives at 00:30 is withdrawable thirty minutes later.
     *
     * Paired with the test above — same rule, opposite side of the boundary.
     */
    public function test_a_credit_just_before_0100_clears_the_same_night(): void
    {
        // 2026-08-01 17:30 UTC = 2026-08-02 00:30 WIB.
        Carbon::setTestNow('2026-08-01 17:30:00');
        [$org] = $this->seedPendingIncome(User::factory()->create());

        $credit = WalletTransaction::where('organization_id', $org->id)->firstOrFail();

        // The same day's 01:00 WIB, not tomorrow's.
        $this->assertSame(
            '2026-08-01 18:00:00',
            $credit->available_at->utc()->format('Y-m-d H:i:s'),
        );

        $this->sweepAt('2026-08-01 18:00:00');
        $this->assertBalances($org, '0.00', '100000.00');
    }

    /**
     * The boundary is read in the wallet timezone. Computed naively in UTC the
     * cut-off would land seven hours away, and this is the hour that tells the
     * two apart: 2026-08-02 01:00 UTC is 08:00 WIB — past a UTC 01:00 but a
     * long way from the WIB one.
     */
    public function test_the_boundary_is_read_in_wib_not_utc(): void
    {
        Carbon::setTestNow('2026-08-01 20:00:00'); // 2026-08-02 03:00 WIB
        [$org] = $this->seedPendingIncome(User::factory()->create());

        $this->sweepAt('2026-08-02 01:00:00');
        $this->assertBalances($org, '100000.00', '0.00', 'A UTC 01:00 must not release anything.');

        // 2026-08-02 18:00 UTC = 2026-08-03 01:00 WIB — the real boundary.
        $this->sweepAt('2026-08-02 18:00:00');
        $this->assertBalances($org, '0.00', '100000.00');
    }

    /**
     * `wallet_hold_days` survives, reinterpreted: extra days stacked on top of
     * the 01:00 boundary. Compared against the same credit under the default 0,
     * because "still held" on its own proves nothing about the shift.
     */
    public function test_hold_days_pushes_the_boundary_by_whole_days(): void
    {
        PlatformSettings::put(['wallet_hold_days' => 1], null);
        PlatformSettings::flush();

        Carbon::setTestNow('2026-08-02 03:00:00'); // 10:00 WIB
        [$org] = $this->seedPendingIncome(User::factory()->create());

        // The boundary a hold of 0 would have produced.
        $this->sweepAt('2026-08-02 18:00:00'); // 2026-08-03 01:00 WIB
        $this->assertBalances($org, '100000.00', '0.00');

        $this->sweepAt('2026-08-03 18:00:00'); // 2026-08-04 01:00 WIB
        $this->assertBalances($org, '0.00', '100000.00');
    }

    /**
     * The clock is the only reader now, so a cancelled event's money is
     * released like anyone else's — the exact opposite of the rule this file
     * used to assert. Refunds are what take that money back, and they already
     * write their own debit.
     */
    public function test_a_cancelled_events_funds_are_still_released_by_the_clock(): void
    {
        Carbon::setTestNow('2026-08-02 03:00:00');
        [$org, $event] = $this->seedPendingIncome(User::factory()->create());

        $event->update(['status' => 'cancelled']);

        $this->sweepAt('2026-08-02 18:00:00');
        $this->assertBalances($org, '0.00', '100000.00');
    }

    /**
     * Closing an event moves no money at all any more. Asserted at an hour
     * before the credit's boundary, so a release here could only have come from
     * the status change.
     */
    public function test_finishing_an_event_releases_nothing(): void
    {
        Carbon::setTestNow('2026-08-02 03:00:00');
        $user = User::factory()->create();
        [$org, $event] = $this->seedPendingIncome($user);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$event->id}/status", ['status' => 'finished'])
            ->assertOk();

        $this->assertBalances($org, '100000.00', '0.00');

        // And the clock still works on it afterwards.
        $this->sweepAt('2026-08-02 18:00:00');
        $this->assertBalances($org, '0.00', '100000.00');
    }

    public function test_release_is_idempotent(): void
    {
        Carbon::setTestNow('2026-08-02 03:00:00');
        [$org] = $this->seedPendingIncome(User::factory()->create());

        $this->sweepAt('2026-08-02 18:00:00');
        $this->artisan('wallet:release')->assertSuccessful();
        $this->artisan('wallet:release')->assertSuccessful();

        $this->assertBalances($org, '0.00', '100000.00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
