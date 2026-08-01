<?php

namespace Tests\Feature;

use App\Models\EventPlanOrder;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * `events:backfill-plan` gives events that predate per-event billing a plan.
 *
 * Without it PlanGate — which reads `events.plan_id` and grants nothing without
 * one — would strip a running tournament of its tickets, certificates and
 * gallery mid-competition.
 */
class BackfillEventPlansTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function professional(): Plan
    {
        return Plan::firstOrCreate(
            ['slug' => 'professional'],
            ['name' => 'Professional', 'price' => 800000, 'sort_order' => 3],
        );
    }

    /** An event with no plan, as it would look straight after the schema migration. */
    private function legacyEvent(Organization $org): string
    {
        $event = $org->events()->create([
            'name' => 'Turnamen Lama',
            'slug' => 'turnamen-lama-'.uniqid(),
            'sport_type' => 'football',
            'status' => 'ongoing',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-10',
        ]);

        // The trait's helpers always attach a plan, so strip it back off to get
        // the pre-migration shape this command exists for.
        $event->forceFill(['plan_id' => null])->save();

        return $event->id;
    }

    /**
     * Running twice must leave exactly what running once did.
     *
     * Idempotence is the whole reason this lives in a command rather than inline
     * in the migration, and only a second run proves it — a comment does not.
     */
    public function test_backfill_is_idempotent_and_grants_professional(): void
    {
        $this->professional();
        $org = $this->orgFor(User::factory()->create());
        $eventId = $this->legacyEvent($org);

        $this->artisan('events:backfill-plan')->assertSuccessful();
        $this->artisan('events:backfill-plan')->assertSuccessful();

        $event = $org->events()->findOrFail($eventId);
        $this->assertSame($this->professional()->id, $event->plan_id);
        $this->assertSame(
            1,
            EventPlanOrder::where('event_id', $eventId)->count(),
            'The second run must not mint a second order.',
        );
    }

    /**
     * The backfilled order is a receipt for a purchase that never happened, so
     * it carries no invoice or receipt number.
     *
     * Minting one would slip a fabricated document into a real sequence — the
     * same reason `plan-orders:expire-manual` never recycles numbers either.
     */
    public function test_the_backfilled_order_carries_no_document_numbers(): void
    {
        $this->professional();
        $org = $this->orgFor(User::factory()->create());
        $eventId = $this->legacyEvent($org);

        $this->artisan('events:backfill-plan')->assertSuccessful();

        $order = EventPlanOrder::where('event_id', $eventId)->firstOrFail();

        $this->assertSame('paid', $order->status);
        $this->assertSame('legacy_migration', $order->payment_type);
        $this->assertNull($order->invoice_number);
        $this->assertNull($order->receipt_number);
        $this->assertSame(0.0, (float) $order->amount);
        $this->assertNotNull($order->consumed_at);
    }

    /**
     * An event that already has a plan is left alone.
     *
     * Compared against a legacy one in the same run: asserting only that the
     * legacy event was filled would pass just as well against a command that
     * overwrote everything.
     */
    public function test_events_that_already_have_a_plan_are_untouched(): void
    {
        $this->professional();
        $org = $this->orgFor(User::factory()->create());

        $starter = $this->planWith(['max_categories' => '1'], 'Starter');
        $existing = $this->eventOn($org, $starter);
        $legacyId = $this->legacyEvent($org);

        $this->artisan('events:backfill-plan')->assertSuccessful();

        $this->assertSame($starter->id, $existing->fresh()->plan_id, 'An event with a plan must keep it.');
        $this->assertSame($this->professional()->id, $org->events()->findOrFail($legacyId)->plan_id);
    }

    /** Nothing to do is a success, not a failure. */
    public function test_backfill_succeeds_when_there_is_nothing_to_do(): void
    {
        $this->professional();
        $this->eventOn($this->orgFor(User::factory()->create()));

        $this->artisan('events:backfill-plan')
            ->expectsOutputToContain('Tidak ada event yang perlu di-backfill.')
            ->assertSuccessful();
    }

    /**
     * The command fails loudly when it writes nothing.
     *
     * Its first run reported "100 event diberi paket" while writing none —
     * `plan_id` was missing from Event::$fillable, so update() dropped it
     * silently. A backfill that cannot tell you it did nothing is worse than one
     * that crashes.
     */
    public function test_backfill_fails_when_the_catalogue_plan_is_missing(): void
    {
        Plan::where('slug', 'professional')->delete();

        $org = $this->orgFor(User::factory()->create());
        $this->legacyEvent($org);

        $this->artisan('events:backfill-plan')->assertFailed();
    }
}
