<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Held funds become withdrawable once the event is over — by its end date
 * passing, or by the organizer closing it early.
 */
class WalletReleaseTest extends TestCase
{
    use RefreshDatabase;

    private function orgWithPlan(User $owner, array $features = []): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        // See WalletTest: PaymentRails gates online payment on this entitlement,
        // and every seeded plan grants it.
        $features = ['payment_gateway' => 'true'] + $features;

        foreach ($features as $key => $value) {
            $plan->features()->create(['feature_key' => $key, 'value' => $value]);
        }

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    /** An org with 95.000 pending from one paid order on an event ending 2026-08-02. */
    private function seedPendingIncome(User $user): array
    {
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true', 'ticket_fee_percent' => '5']);
        $event = $org->events()->create([
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'tournament_format' => 'league', 'status' => 'open',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-02',
        ]);
        $category = $event->ticketCategories()->create(['name' => 'Reguler', 'price' => 50000, 'is_active' => true]);

        $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
            'ticket_category_id' => $category->id,
            'quantity' => 2,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
        ])->assertCreated();

        return [$org, $event];
    }

    public function test_funds_are_released_after_the_event_ends(): void
    {
        [$org] = $this->seedPendingIncome(User::factory()->create());

        Carbon::setTestNow('2026-08-03 12:00:00');
        $this->artisan('wallet:release')->assertSuccessful();

        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_pending' => '0.00',
            'balance_available' => '95000.00',
        ]);
        $this->assertDatabaseHas('wallet_transactions', ['organization_id' => $org->id, 'status' => 'available']);
    }

    public function test_release_is_idempotent(): void
    {
        [$org] = $this->seedPendingIncome(User::factory()->create());

        Carbon::setTestNow('2026-08-03 12:00:00');
        $this->artisan('wallet:release')->assertSuccessful();
        $this->artisan('wallet:release')->assertSuccessful();

        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_available' => '95000.00',
            'balance_pending' => '0.00',
        ]);
    }

    public function test_funds_stay_held_while_the_event_is_still_running(): void
    {
        [$org] = $this->seedPendingIncome(User::factory()->create());

        Carbon::setTestNow('2026-08-01 12:00:00');
        $this->artisan('wallet:release')->assertSuccessful();

        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_pending' => '95000.00',
            'balance_available' => '0.00',
        ]);
    }

    /**
     * The event's last day ends at midnight WIB, not at 00:00 UTC. Releasing on
     * the naive UTC date would hand over the money at 07:00 WIB — mid-event.
     */
    public function test_release_respects_the_wib_end_of_day_boundary(): void
    {
        [$org] = $this->seedPendingIncome(User::factory()->create());

        // 2026-08-02 10:00 UTC = 17:00 WIB on the final day. Still playing.
        Carbon::setTestNow('2026-08-02 10:00:00');
        $this->artisan('wallet:release')->assertSuccessful();
        $this->assertDatabaseHas('wallets', ['organization_id' => $org->id, 'balance_available' => '0.00']);

        // 2026-08-02 17:00 UTC = 00:00 WIB the next day. Over.
        Carbon::setTestNow('2026-08-02 17:00:00');
        $this->artisan('wallet:release')->assertSuccessful();
        $this->assertDatabaseHas('wallets', ['organization_id' => $org->id, 'balance_available' => '95000.00']);
    }

    public function test_cancelled_event_funds_are_never_released(): void
    {
        [$org, $event] = $this->seedPendingIncome(User::factory()->create());
        $event->update(['status' => 'cancelled']);

        Carbon::setTestNow('2026-09-01 12:00:00');
        $this->artisan('wallet:release')->assertSuccessful();

        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_pending' => '95000.00',
            'balance_available' => '0.00',
        ]);
    }

    public function test_marking_an_event_finished_releases_funds_immediately(): void
    {
        $user = User::factory()->create();
        [$org, $event] = $this->seedPendingIncome($user);

        // Well before end_date — closing the event is what releases the money.
        Carbon::setTestNow('2026-07-20 12:00:00');

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$event->id}", ['status' => 'finished'])
            ->assertOk();

        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_pending' => '0.00',
            'balance_available' => '95000.00',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
