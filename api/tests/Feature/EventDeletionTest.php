<?php

namespace Tests\Feature;

use App\Models\EventPlanOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * What may be deleted, and what happens to the plan when it is.
 *
 * Deleting an event used to be unguarded, and two separate things fell out of
 * it: `event_plan_orders.event_id` is `nullOnDelete`, so the credit came back
 * and one payment bought unlimited events; and `certificates` / `ticket_orders`
 * cascade, so a finished event took its own paid history with it.
 */
class EventDeletionTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    public function test_a_finished_event_cannot_be_deleted_and_its_credit_stays_spent(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org, null, ['status' => 'finished']);

        $this->actingAs($owner, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/events/{$event->id}")
            ->assertStatus(422);

        // The whole point, stated as the count: buying one plan must not buy a
        // second event. Asserting only the 422 would pass even if the refusal
        // arrived after the credit had already been handed back.
        $this->assertSame(0, $org->planOrders()->unconsumed()->count());
        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }

    public function test_a_published_event_cannot_be_deleted(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org, null, ['status' => 'open']);

        $this->actingAs($owner, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/events/{$event->id}")
            ->assertStatus(422);
    }

    public function test_a_draft_with_registrations_cannot_be_deleted(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org, null, ['status' => 'draft']);
        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'league',
        ]);
        $event->teams()->create(['category_id' => $category->id, 'name' => 'Tim A', 'status' => 'approved']);

        // A draft is normally empty, so the status check alone would let this
        // through — and a registered team is somebody else's data.
        $this->actingAs($owner, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/events/{$event->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }

    public function test_an_untouched_draft_is_deleted_and_gives_the_credit_back(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->creditFor($org);

        $event = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", [
                'name' => 'Salah Bikin',
                'sport_type' => 'futsal',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-02',
                'categories' => [['name' => 'Umum', 'tournament_format' => 'league']],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(0, $org->planOrders()->unconsumed()->count());

        $this->actingAs($owner, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/events/{$event['id']}")
            ->assertOk();

        // Back to spendable — a mis-click must not cost 150.000. `consumed_at`
        // is cleared explicitly here: `nullOnDelete` only reaches `event_id`,
        // and scopeUnconsumed() reads both.
        $this->assertSame(1, $org->planOrders()->unconsumed()->count());
        $this->assertNull(EventPlanOrder::where('organization_id', $org->id)->value('consumed_at'));
    }

    public function test_the_returned_credit_can_actually_be_spent_again(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->creditFor($org);

        $make = fn (string $name) => $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", [
                'name' => $name,
                'sport_type' => 'futsal',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-02',
                'categories' => [['name' => 'Umum', 'tournament_format' => 'league']],
            ]);

        $first = $make('Pertama')->assertCreated()->json('data.id');

        $this->actingAs($owner, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/events/{$first}")
            ->assertOk();

        $make('Kedua')->assertCreated();

        // And only once more — the credit returned, it did not multiply.
        $make('Ketiga')->assertStatus(403);
    }
}
