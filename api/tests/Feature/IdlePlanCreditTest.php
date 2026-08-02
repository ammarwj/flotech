<?php

namespace Tests\Feature;

use App\Models\EventPlanOrder;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\PlanOrderIdle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Reminding organizers about a plan they paid for and never spent.
 *
 * The credit never expires — taking it back would be taking money — so a nudge
 * is the only lever, and the only thing that can go wrong is nudging the wrong
 * people or the same person forever.
 */
class IdlePlanCreditTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function idleCredit(string $orgName, int $daysAgo): EventPlanOrder
    {
        $org = $this->orgFor(User::factory()->create(), $orgName);
        $order = $this->creditFor($org);
        $order->update(['amount' => 150000, 'paid_at' => now()->subDays($daysAgo)]);

        return $order->fresh();
    }

    public function test_only_credits_past_the_threshold_are_reminded(): void
    {
        Notification::fake();

        $old = $this->idleCredit('Sudah lama', 20);
        $fresh = $this->idleCredit('Baru kemarin', 2);

        $this->artisan('plan-orders:remind-idle')->assertSuccessful();

        // Comparative: a sweep that mailed everyone would satisfy an assertion
        // about the old one alone.
        Notification::assertSentTo($old->organization->owner, PlanOrderIdle::class);
        Notification::assertNotSentTo($fresh->organization->owner, PlanOrderIdle::class);
    }

    public function test_a_spent_credit_is_never_reminded(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->eventOn($org);
        EventPlanOrder::where('organization_id', $org->id)
            ->update(['amount' => 150000, 'paid_at' => now()->subDays(60)]);

        $this->artisan('plan-orders:remind-idle')->assertSuccessful();

        // It bought an event. Telling its owner it is "still waiting" would be
        // wrong twice over — the plan is in use, and nothing is owed.
        Notification::assertNothingSent();
    }

    public function test_a_top_up_bill_and_a_retired_order_are_not_credits_to_chase(): void
    {
        Notification::fake();

        $starter = Plan::where('slug', 'starter')->firstOrFail();
        $pro = Plan::where('slug', 'pro')->firstOrFail();

        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $order = $this->creditFor($org, $starter);
        $order->update(['amount' => $starter->price, 'paid_at' => now()->subDays(40)]);

        $upgradeId = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$order->id}/upgrade", ['plan_id' => $pro->id])
            ->assertCreated()
            ->json('data.plan_order.id');

        $upgrade = EventPlanOrder::findOrFail($upgradeId);
        $upgrade->update(['status' => 'paid', 'paid_at' => now()->subDays(40)]);
        app(\App\Services\EventPlanOrderService::class)->activate($upgrade->fresh());

        $this->artisan('plan-orders:remind-idle')->assertSuccessful();

        // Three paid rows exist between them, but only one is an unspent
        // entitlement — so exactly one reminder, not three. The command leans on
        // scopeUnconsumed() for that rather than re-deciding it.
        Notification::assertSentToTimes($owner, PlanOrderIdle::class, 1);
    }

    public function test_the_same_credit_is_not_reminded_again_the_next_day(): void
    {
        Notification::fake();

        $credit = $this->idleCredit('Menganggur', 20);

        $this->artisan('plan-orders:remind-idle')->assertSuccessful();
        $this->assertNotNull($credit->fresh()->idle_reminded_at);

        // A daily schedule with no memory mails the same person every morning
        // for as long as they hold the credit. The stamp is what stops it.
        $this->artisan('plan-orders:remind-idle')->assertSuccessful();

        Notification::assertSentToTimes($credit->organization->owner, PlanOrderIdle::class, 1);
    }

    public function test_the_reminder_comes_back_after_the_repeat_gap(): void
    {
        Notification::fake();

        $credit = $this->idleCredit('Menganggur lama', 90);
        $credit->update(['idle_reminded_at' => now()->subDays(60)]);

        $this->artisan('plan-orders:remind-idle')->assertSuccessful();

        Notification::assertSentTo($credit->organization->owner, PlanOrderIdle::class);
    }

    public function test_dry_run_reports_without_sending_or_stamping(): void
    {
        Notification::fake();

        $credit = $this->idleCredit('Menganggur', 20);

        $this->artisan('plan-orders:remind-idle', ['--dry-run' => true])->assertSuccessful();

        Notification::assertNothingSent();
        // The stamp must not move either: a dry run that marked them read would
        // silence the real sweep it was meant to preview.
        $this->assertNull($credit->fresh()->idle_reminded_at);
    }

    public function test_the_admin_ledger_lists_the_same_credits(): void
    {
        $old = $this->idleCredit('Lama', 30);
        $this->idleCredit('Baru', 1);

        $admin = User::factory()->create(['role' => 'super_admin']);

        $rows = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/plan-orders/idle')
            ->assertOk()
            ->json('data');

        $this->assertSame([$old->id], array_column($rows, 'id'));
    }

    public function test_the_admin_ledger_is_super_admin_only(): void
    {
        $this->idleCredit('Lama', 30);

        $this->actingAs(User::factory()->create(), 'api')
            ->getJson('/api/v1/admin/plan-orders/idle')
            ->assertStatus(403);
    }
}
