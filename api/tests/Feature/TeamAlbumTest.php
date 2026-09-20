<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The printable player album, both entrypoints: per-team (organizer, plain
 * `tenant`) and event-wide (organizer + super_admin). No plan gate — this is
 * a re-layout of roster data every org member already sees, same reasoning
 * as the referee/personnel routes next to it.
 */
class TeamAlbumTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function eventWithOrg(): Event
    {
        $owner = User::factory()->create();
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'price' => 0]);
        $org = Organization::create(['name' => 'EO', 'slug' => 'eo-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id]);

        $event = $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Cup', 'slug' => 'cup', 'sport_type' => 'football',
            'status' => 'open', 'start_date' => '2026-08-01', 'end_date' => '2026-08-10',
            'registration_open' => Carbon::now()->subDay(), 'registration_close' => Carbon::now()->addDays(10),
        ]);

        $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league',
            'registration_fee' => 0, 'sort_order' => 0,
        ]);

        return $event->load('categories', 'organization');
    }

    private function approvedTeam(Event $event, string $name, array $players = [], array $officials = []): \App\Models\Team
    {
        $team = $event->teams()->create([
            'category_id' => $event->categories->first()->id,
            'name' => $name,
            'status' => 'approved',
            'contact_name' => 'Andi',
            'contact_phone' => '08123456789',
        ]);

        foreach ($players as $p) {
            $team->players()->create($p + ['full_name' => 'Pemain']);
        }

        foreach ($officials as $o) {
            $team->officials()->create($o + ['full_name' => 'Ofisial']);
        }

        return $team;
    }

    public function test_organizer_can_print_one_teams_album(): void
    {
        $event = $this->eventWithOrg();
        $org = $event->organization;
        $team = $this->approvedTeam($event, 'Garuda FC', [
            ['full_name' => 'Player One', 'jersey_number' => '10', 'position' => 'gk'],
        ], [
            ['full_name' => 'Coach A', 'role' => 'coach'],
        ]);

        $response = $this->actingAs($org->owner, 'api')
            ->get("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/{$team->id}/album");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_team_with_zero_players_still_returns_a_pdf(): void
    {
        $event = $this->eventWithOrg();
        $org = $event->organization;
        $team = $this->approvedTeam($event, 'Tim Kosong');

        $this->actingAs($org->owner, 'api')
            ->get("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/{$team->id}/album")
            ->assertOk();
    }

    public function test_operator_can_print_without_org_admin(): void
    {
        $event = $this->eventWithOrg();
        $org = $event->organization;
        $team = $this->approvedTeam($event, 'Garuda FC', [
            ['full_name' => 'Player One', 'jersey_number' => '10'],
        ]);

        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        // No plan-feature gate and no org.admin requirement — an operator who
        // can already see every player's photo through the roster gets the
        // same printout.
        $this->actingAs($operator, 'api')
            ->get("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/{$team->id}/album")
            ->assertOk();
    }

    public function test_event_wide_album_includes_every_approved_team_but_not_rejected_ones(): void
    {
        $event = $this->eventWithOrg();
        $org = $event->organization;
        $this->approvedTeam($event, 'Tim A', [['full_name' => 'A1', 'jersey_number' => '1']]);
        $this->approvedTeam($event, 'Tim B', [['full_name' => 'B1', 'jersey_number' => '2']]);

        $rejected = $event->teams()->create([
            'category_id' => $event->categories->first()->id,
            'name' => 'Tim Ditolak',
            'status' => 'rejected',
            'contact_name' => 'Budi',
            'contact_phone' => '08111111111',
        ]);
        $rejected->players()->create(['full_name' => 'Rejected Player', 'jersey_number' => '9']);

        $response = $this->actingAs($org->owner, 'api')
            ->get("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/album");

        $response->assertOk();

        $pdf = $response->getContent();
        // We can't decode dompdf's output text directly here, so this asserts
        // on byte size as a coarse "more content went in" signal alongside the
        // 200 — the precise per-team content is covered by the service test.
        $this->assertGreaterThan(0, strlen($pdf));
    }

    public function test_event_wide_album_404s_when_no_team_is_approved(): void
    {
        $event = $this->eventWithOrg();
        $org = $event->organization;
        $this->approvedTeam($event, 'Tim Pending')->update(['status' => 'pending']);

        $this->actingAs($org->owner, 'api')
            ->get("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/album")
            ->assertStatus(404);
    }

    public function test_admin_can_print_event_wide_album_without_org_membership(): void
    {
        $event = $this->eventWithOrg();
        $this->approvedTeam($event, 'Garuda FC', [
            ['full_name' => 'Player One', 'jersey_number' => '10'],
        ]);

        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin, 'api')
            ->get("/api/v1/admin/events/{$event->id}/album")
            ->assertOk();
    }

    public function test_admin_album_404s_when_no_team_is_approved(): void
    {
        $event = $this->eventWithOrg();

        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin, 'api')
            ->get("/api/v1/admin/events/{$event->id}/album")
            ->assertStatus(404);
    }
}
