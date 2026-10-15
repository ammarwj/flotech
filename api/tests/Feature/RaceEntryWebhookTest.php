<?php

namespace Tests\Feature;

use App\Models\EventPlanOrder;
use App\Models\RaceCategory;
use App\Models\RaceEntry;
use App\Models\TicketOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Midtrans settlement routing, which is prefix-based and has one trap.
 *
 * `MidtransWebhookController::handle()` matches `TIX-`, `REG-` and `RUN-`
 * explicitly and lets everything else fall through to the plan-order arm — that
 * `default` is deliberate, because plan ids were once minted `SUB-` and are now
 * `PLN-`, so matching either explicitly would strand the other.
 *
 * Note what is NOT a hazard, because it looks like one: `match` evaluates
 * `default` last whatever position it is written in, so an arm cannot be
 * shadowed by sitting below it. (A `switch` would behave the other way, and
 * this was verified rather than assumed.) The real hazard is a MISSING arm —
 * the settlement lands in handlePlanOrder(), finds no row, and answers 404,
 * which Midtrans retries a few times and then abandons, leaving the row pending
 * forever with nothing logged.
 *
 * That is what the three-id comparison catches, and why the 404 test asserts the
 * MESSAGE: it names which arm replied. Asserting a `RUN-` payload settles its
 * entry would pass on its own even if the race arm were gone and some other arm
 * happened to do the work.
 */
class RaceEntryWebhookTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private const KEY = 'test-server-key';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Notification::fake() as well as Mail::fake(), and not for tidiness:
        // the plan-order arm below notifies the owner, that notification
        // attaches a dompdf receipt, and dompdf leaves an output buffer open
        // which PHPUnit reports as a risky test. Mail::fake() does not cover
        // notifications — two fakes because there are two delivery paths.
        Notification::fake();
        config()->set('services.midtrans.server_key', self::KEY);
        Carbon::setTestNow('2026-01-05 03:00:00'); // inside Early Bird below
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $orderId, string $amount, string $status = 'settlement'): array
    {
        $payload = [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => $amount,
            'transaction_status' => $status,
        ];

        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::KEY,
        );

        return $payload;
    }

    private function raceSetup(): RaceCategory
    {
        $org = $this->orgFor(User::factory()->create());

        $event = $this->eventOn($org, null, [
            'sport_type' => 'fun_run',
            'status' => 'open',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-01',
        ]);

        $period = $event->racePeriods()->create([
            'name' => 'Early Bird',
            'starts_at' => '2026-01-01T00:00:00+07:00',
            'ends_at' => '2026-01-10T23:59:59+07:00',
        ]);

        $category = $event->raceCategories()->create(['name' => 'Umum', 'slug' => 'umum']);
        $category->prices()->create(['race_period_id' => $period->id, 'price' => 75_000]);

        return $category->load(['prices', 'event.racePeriods']);
    }

    /**
     * A `RUN-` id reaches the race arm, and the two neighbouring prefixes still
     * reach theirs. Three ids, one test: that is what proves the new arm sits
     * above the default rather than below it.
     */
    public function test_a_run_prefix_settles_its_entry_without_disturbing_the_other_rails(): void
    {
        $category = $this->raceSetup();

        $entry = $category->entries()->create([
            'event_id' => $category->event_id,
            'full_name' => 'Ammar',
            'email' => 'ammar@test.com',
            'race_period_id' => $category->prices->first()->race_period_id,
            'period_name' => 'Early Bird',
            'price' => 75_000,
            'status' => 'pending',
            'midtrans_order_id' => 'RUN-ABCDEFGHIJ',
        ]);

        $ticketOrg = $this->orgFor(User::factory()->create());
        $ticketEvent = $this->eventOn($ticketOrg);
        $ticketCategory = $ticketEvent->ticketCategories()->create([
            'name' => 'Reguler', 'price' => 50_000, 'is_active' => true,
        ]);
        $order = TicketOrder::create([
            'event_id' => $ticketEvent->id,
            'ticket_category_id' => $ticketCategory->id,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
            'quantity' => 1,
            'seats' => 1,
            'unit_price' => 50_000,
            'total_price' => 50_000,
            'status' => 'pending',
            'midtrans_order_id' => 'TIX-ABCDEFGHIJ',
        ]);

        $planOrder = EventPlanOrder::create([
            'organization_id' => $ticketOrg->id,
            'plan_id' => $this->planId(),
            'amount' => 350_000,
            'status' => 'past_due',
            'payment_method' => 'gateway',
            'midtrans_order_id' => 'PLN-ABCDEFGHIJ',
        ]);

        $this->postJson('/api/v1/webhooks/midtrans', $this->payload('RUN-ABCDEFGHIJ', '75000.00'))->assertOk();
        $this->postJson('/api/v1/webhooks/midtrans', $this->payload('TIX-ABCDEFGHIJ', '50000.00'))->assertOk();
        $this->postJson('/api/v1/webhooks/midtrans', $this->payload('PLN-ABCDEFGHIJ', '350000.00'))->assertOk();

        // All three, compared: the race entry settled, and neither of the arms
        // it was threaded in front of stopped working.
        $this->assertSame('paid', $entry->fresh()->status);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('paid', $planOrder->fresh()->status);
    }

    /**
     * The failure arms are wired too, and they matter more here than for a team
     * registration: an abandoned entry is holding a place in a quota, and the
     * only other thing that gives it back is the hourly sweep — which never
     * touches a gateway order.
     */
    public function test_a_failed_gateway_settlement_cancels_the_entry_and_frees_the_place(): void
    {
        $category = $this->raceSetup();

        $entry = $category->entries()->create([
            'event_id' => $category->event_id,
            'full_name' => 'Ammar',
            'email' => 'ammar@test.com',
            'race_period_id' => $category->prices->first()->race_period_id,
            'period_name' => 'Early Bird',
            'price' => 75_000,
            'status' => 'pending',
            'midtrans_order_id' => 'RUN-FAILFAILFA',
        ]);
        $category->increment('taken');

        $this->postJson('/api/v1/webhooks/midtrans', $this->payload('RUN-FAILFAILFA', '75000.00', 'expire'))->assertOk();

        $this->assertSame('cancelled', $entry->fresh()->status);
        // The half that says cancel() ran rather than a bare status update:
        // asserting the status alone would pass with the place still held.
        $this->assertSame(0, $category->fresh()->taken);
    }

    /**
     * A re-delivered settlement is a no-op. Compared across two identical
     * posts: one ledger row, one receipt number, one of everything.
     */
    public function test_a_redelivered_settlement_changes_nothing(): void
    {
        $category = $this->raceSetup();

        $entry = $category->entries()->create([
            'event_id' => $category->event_id,
            'full_name' => 'Ammar',
            'email' => 'ammar@test.com',
            'race_period_id' => $category->prices->first()->race_period_id,
            'period_name' => 'Early Bird',
            'price' => 75_000,
            'status' => 'pending',
            'midtrans_order_id' => 'RUN-TWICETWICE',
        ]);

        $payload = $this->payload('RUN-TWICETWICE', '75000.00');

        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
        $receipt = $entry->fresh()->receipt_number;

        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

        $this->assertNotNull($receipt);
        // The same receipt, not a second one: a re-delivered webhook must not
        // issue a new document for one payment.
        $this->assertSame($receipt, $entry->fresh()->receipt_number);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    /** An id nobody minted reaches the plan arm and 404s there, harmlessly. */
    public function test_an_unknown_order_id_is_a_404_not_a_race_entry(): void
    {
        $this->postJson('/api/v1/webhooks/midtrans', $this->payload('RUN-NOTHINGHE', '75000.00'))
            ->assertNotFound()
            ->assertJsonPath('message', 'Entry lomba tidak ditemukan.');

        // And the message proves WHICH arm answered. A plain 404 assertion
        // would pass even if the `RUN-` arm were unreachable and the plan arm
        // had replied instead.
        $this->postJson('/api/v1/webhooks/midtrans', $this->payload('WHAT-EVER', '75000.00'))
            ->assertNotFound()
            ->assertJsonPath('message', 'Pembelian paket tidak ditemukan.');
    }
}
