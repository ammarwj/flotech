<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The undo for the one dead end Event::TRANSITIONS has on purpose: `ongoing`
 * cannot go back to `open`/`registration_closed` through the normal status
 * endpoint, so an organizer who fat-fingers "Berlangsung" has nowhere to click.
 * This is the super-admin-only escape hatch — see
 * Api\Admin\EventController::forceStatus().
 */
class AdminForceEventStatusTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function org(): Organization
    {
        return Organization::create([
            'name' => 'Org '.uniqid(),
            'slug' => 'org-'.uniqid(),
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    private function event(Organization $org, string $status): Event
    {
        return $org->events()->create([
            'name' => 'Ladang Selatan Cup',
            'slug' => 'cup-'.uniqid(),
            'sport_type' => 'futsal',
            'status' => $status,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-02',
        ]);
    }

    private function url(string $eventId): string
    {
        return "/api/v1/admin/events/{$eventId}/force-status";
    }

    public function test_super_admin_can_force_a_status_the_state_machine_forbids(): void
    {
        $event = $this->event($this->org(), 'ongoing');

        // The normal endpoint has no way back from `ongoing` — proving the bug
        // this feature exists for, not just testing the new route in isolation.
        $this->assertSame([], array_intersect(['open', 'registration_closed'], $event->nextStatuses()));

        $this->actingAs($this->superAdmin(), 'api')
            ->patchJson($this->url($event->id), [
                'status' => 'open',
                'reason' => 'Organizer salah klik, seharusnya masih pendaftaran dibuka.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'open');

        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'open']);

        $this->assertDatabaseHas('event_status_overrides', [
            'event_id' => $event->id,
            'from_status' => 'ongoing',
            'to_status' => 'open',
            'reason' => 'Organizer salah klik, seharusnya masih pendaftaran dibuka.',
        ]);
    }

    public function test_reason_is_required(): void
    {
        $event = $this->event($this->org(), 'ongoing');

        $this->actingAs($this->superAdmin(), 'api')
            ->patchJson($this->url($event->id), ['status' => 'open'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'ongoing']);
        $this->assertDatabaseCount('event_status_overrides', 0);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $event = $this->event($this->org(), 'ongoing');

        $this->actingAs($this->superAdmin(), 'api')
            ->patchJson($this->url($event->id), [
                'status' => 'not_a_real_status',
                'reason' => 'test',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    /**
     * Forcing INTO `cancelled` snapshots the status it left, same as
     * EventController::transition(); forcing to anything else clears it, so a
     * stale snapshot from some earlier cancellation can't decide where a later
     * `cancelled` restore lands.
     */
    public function test_status_before_cancel_is_snapshotted_and_cleared_like_a_normal_transition(): void
    {
        $event = $this->event($this->org(), 'ongoing');
        $event->update(['status_before_cancel' => 'open']);

        $this->actingAs($this->superAdmin(), 'api')
            ->patchJson($this->url($event->id), [
                'status' => 'cancelled',
                'reason' => 'test cancel',
            ])
            ->assertOk();

        $this->assertDatabaseHas('events', ['id' => $event->id, 'status_before_cancel' => 'ongoing']);

        $this->actingAs($this->superAdmin(), 'api')
            ->patchJson($this->url($event->id), [
                'status' => 'registration_closed',
                'reason' => 'test clear',
            ])
            ->assertOk();

        $this->assertDatabaseHas('events', ['id' => $event->id, 'status_before_cancel' => null]);
    }

    public function test_organizer_cannot_reach_the_super_admin_route(): void
    {
        $owner = User::factory()->create();
        $org = $this->org();
        $org->update(['owner_id' => $owner->id]);
        $event = $this->event($org, 'ongoing');

        $this->actingAs($owner, 'api')
            ->patchJson($this->url($event->id), [
                'status' => 'open',
                'reason' => 'test',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'ongoing']);
    }

    public function test_each_override_is_its_own_ledger_row(): void
    {
        $event = $this->event($this->org(), 'ongoing');
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'api')->patchJson($this->url($event->id), [
            'status' => 'open',
            'reason' => 'first fix',
        ])->assertOk();

        $this->actingAs($admin, 'api')->patchJson($this->url($event->id), [
            'status' => 'registration_closed',
            'reason' => 'second fix',
        ])->assertOk();

        $this->assertDatabaseCount('event_status_overrides', 2);
        $this->assertDatabaseHas('event_status_overrides', [
            'event_id' => $event->id,
            'from_status' => 'open',
            'to_status' => 'registration_closed',
            'reason' => 'second fix',
            'created_by' => $admin->id,
        ]);
    }
}
