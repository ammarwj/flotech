<?php

namespace Tests\Feature;

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
