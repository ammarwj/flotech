<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

class EventTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /**
     * An organization holding `$credits` unspent plan orders, since creating an
     * event now spends one.
     *
     * @param  array<string, string>  $features
     */
    private function orgWithPlan(User $owner, array $features = [], int $credits = 1): Organization
    {
        $org = $this->orgFor($owner);
        $plan = $features === [] ? $this->fullPlan() : $this->planWith($features);

        for ($i = 0; $i < $credits; $i++) {
            $this->creditFor($org, $plan);
        }

        return $org;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jakarta Cup 2026',
            'sport_type' => 'football',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-10',
            'categories' => [
                ['name' => 'Umum', 'tournament_format' => 'league', 'registration_fee' => 1500000],
            ],
        ], $overrides);
    }

    public function test_member_can_create_event(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.slug', 'jakarta-cup-2026')
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('events', ['organization_id' => $org->id, 'name' => 'Jakarta Cup 2026']);
    }

    /**
     * One credit buys one event.
     *
     * Compares the two calls rather than asserting the refusal alone: a 403 on
     * its own would pass just as happily if the second event had been created
     * anyway, which is the failure this guards.
     */
    public function test_one_credit_creates_exactly_one_event(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->assertCreated();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload(['name' => 'Second']))
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'plan_order_required');

        $this->assertSame(1, $org->events()->count());
        $this->assertSame(0, $org->planOrders()->unconsumed()->count());
    }

    public function test_non_member_cannot_create_event(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->assertStatus(403);
    }

    public function test_member_can_update_and_publish_event(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);

        $eventId = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->json('data.id');

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$eventId}", ['name' => 'Renamed Cup'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Cup');

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$eventId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    /** A published event, ready to be walked through the rest of its life. */
    private function openEvent(User $user, Organization $org): string
    {
        $eventId = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->json('data.id');

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$eventId}/publish")
            ->assertOk();

        return $eventId;
    }

    private function statusUrl(Organization $org, string $eventId): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$eventId}/status";
    }

    public function test_event_walks_through_its_statuses(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->openEvent($user, $org);

        foreach (['registration_closed', 'ongoing', 'finished'] as $status) {
            $this->actingAs($user, 'api')
                ->patchJson($this->statusUrl($org, $eventId), ['status' => $status])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
        }

        $this->assertDatabaseHas('events', ['id' => $eventId, 'status' => 'finished']);
    }

    public function test_next_statuses_are_published_with_the_event(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->openEvent($user, $org);

        // The dashboard renders its buttons from this, so it must match the table.
        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$eventId}")
            ->assertOk()
            ->assertJsonPath('data.next_statuses', ['registration_closed', 'ongoing', 'finished', 'cancelled']);
    }

    public function test_a_finished_event_is_terminal(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->openEvent($user, $org);

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'finished'])
            ->assertOk()
            ->assertJsonPath('data.next_statuses', []);

        // Reopening would claim registrations for an event whose funds are out.
        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'open'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('events', ['id' => $eventId, 'status' => 'finished']);
    }

    public function test_a_draft_cannot_skip_straight_to_finished(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);

        $eventId = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->json('data.id');

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'finished'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('events', ['id' => $eventId, 'status' => 'draft']);
    }

    public function test_registration_can_be_reopened(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->openEvent($user, $org);

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'registration_closed'])
            ->assertOk();

        // The one move that goes backwards: closing registration is a mistake
        // an organizer must be able to undo.
        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    /** An event cancelled from `$from`, ready to be reactivated. */
    private function cancelledFrom(User $user, Organization $org, string $from, string $name): string
    {
        $eventId = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload(['name' => $name]))
            ->json('data.id');

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$eventId}/publish")
            ->assertOk();

        if ($from !== 'open') {
            $this->actingAs($user, 'api')
                ->patchJson($this->statusUrl($org, $eventId), ['status' => $from])
                ->assertOk();
        }

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'cancelled'])
            ->assertOk();

        return $eventId;
    }

    /**
     * Cancelling is the one move with a way back, and it goes back to exactly
     * where the event stood.
     *
     * Two events in the same org cancelled from different points, compared:
     * a restore that always landed on one fixed status would satisfy either of
     * them on its own.
     */
    public function test_a_cancelled_event_returns_to_where_it_stood(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, credits: 2);

        $closed = $this->cancelledFrom($user, $org, 'registration_closed', 'Closed Cup');
        $running = $this->cancelledFrom($user, $org, 'ongoing', 'Running Cup');

        // The dashboard renders its one button from this.
        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$closed}")
            ->assertJsonPath('data.next_statuses', ['registration_closed']);
        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$running}")
            ->assertJsonPath('data.next_statuses', ['ongoing']);

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $closed), ['status' => 'registration_closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'registration_closed');
        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $running), ['status' => 'ongoing'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ongoing');
    }

    public function test_reactivating_clears_the_snapshot(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->cancelledFrom($user, $org, 'registration_closed', 'Jakarta Cup 2026');

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'registration_closed'])
            ->assertOk();

        $this->assertDatabaseHas('events', ['id' => $eventId, 'status_before_cancel' => null]);

        // Cancelled a second time from somewhere else, it comes back to the
        // status it left this time — not the one it left before.
        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'ongoing'])
            ->assertOk();
        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.next_statuses', ['ongoing']);
    }

    public function test_a_cancelled_event_can_only_be_reactivated(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->cancelledFrom($user, $org, 'open', 'Jakarta Cup 2026');

        // Coming back is the only move; finishing a cancelled event would pay
        // out money the platform stopped holding for it.
        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'finished'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('events', ['id' => $eventId, 'status' => 'cancelled']);
    }

    /**
     * Reactivation never hands the plan credit back.
     *
     * A draft with nothing attached can be deleted, and deleting it returns the
     * credit — so a published event reaching `draft` through cancel-then-restore
     * would turn one payment into unlimited events. Counting the credits is the
     * test: asserting `draft` is absent from next_statuses would stay green even
     * if the order had been released along the way.
     */
    public function test_a_published_event_can_never_be_reactivated_into_a_draft(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->cancelledFrom($user, $org, 'open', 'Jakarta Cup 2026');

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'draft'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'open'])
            ->assertOk();

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/events/{$eventId}")
            ->assertStatus(422);

        $this->assertSame(0, $org->planOrders()->unconsumed()->count());
        $this->assertDatabaseHas('event_plan_orders', ['organization_id' => $org->id, 'event_id' => $eventId]);
    }

    /** Cancelled before the snapshot column existed: it still has a way back. */
    public function test_a_legacy_cancelled_event_falls_back_to_registration_closed(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->cancelledFrom($user, $org, 'ongoing', 'Jakarta Cup 2026');

        Event::where('id', $eventId)->update(['status_before_cancel' => null]);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$eventId}")
            ->assertJsonPath('data.next_statuses', ['registration_closed']);

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'registration_closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'registration_closed');
    }

    public function test_saving_the_form_cannot_change_the_status(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->openEvent($user, $org);

        // The transition table would be pointless if the form save walked past
        // it — and 'finished' pays the organizer out.
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$eventId}", [
                'name' => 'Renamed Cup',
                'status' => 'finished',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Cup')
            ->assertJsonPath('data.status', 'open');

        $this->assertDatabaseHas('events', ['id' => $eventId, 'status' => 'open']);
    }

    public function test_publishing_an_already_published_event_is_rejected(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->openEvent($user, $org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$eventId}/publish")
            ->assertStatus(422);
    }

    public function test_status_cannot_be_changed_from_another_org(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner);
        $eventId = $this->openEvent($owner, $org);

        $intruder = User::factory()->create();
        $otherOrg = $this->orgWithPlan($intruder, ['max_active_events' => '5']);

        $this->actingAs($intruder, 'api')
            ->patchJson($this->statusUrl($otherOrg, $eventId), ['status' => 'cancelled'])
            ->assertNotFound();

        $this->assertDatabaseHas('events', ['id' => $eventId, 'status' => 'open']);
    }
}
