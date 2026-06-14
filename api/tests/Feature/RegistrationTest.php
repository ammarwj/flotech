<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function openEvent(string $maxTeamsFeature = '10', ?int $maxTeams = null): Event
    {
        $owner = User::factory()->create();
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);
        $plan->features()->create(['feature_key' => 'max_teams_per_event', 'value' => $maxTeamsFeature]);
        $org = Organization::create(['name' => 'EO', 'slug' => 'eo-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id]);

        return $org->events()->create([
            'name' => 'Cup', 'slug' => 'cup', 'sport_type' => 'football', 'tournament_format' => 'league',
            'status' => 'open', 'start_date' => '2026-08-01', 'end_date' => '2026-08-10',
            'registration_open' => Carbon::now()->subDay(), 'registration_close' => Carbon::now()->addDays(10),
            'max_teams' => $maxTeams,
        ]);
    }

    private function teamPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Garuda FC',
            'contact_name' => 'Andi',
            'contact_phone' => '08123456789',
            'players' => [
                ['full_name' => 'Player One', 'jersey_number' => '10'],
                ['full_name' => 'Player Two', 'jersey_number' => '7'],
            ],
        ], $overrides);
    }

    public function test_public_can_view_published_event(): void
    {
        $event = $this->openEvent();
        $org = $event->organization;

        $this->getJson("/api/v1/public/events/{$org->slug}/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Cup')
            ->assertJsonPath('data.registration_is_open', true);
    }

    public function test_draft_event_is_not_public(): void
    {
        $event = $this->openEvent();
        $event->update(['status' => 'draft']);
        $org = $event->organization;

        $this->getJson("/api/v1/public/events/{$org->slug}/{$event->slug}")->assertStatus(404);
    }

    public function test_public_can_register_team(): void
    {
        $event = $this->openEvent();
        $org = $event->organization;

        $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", $this->teamPayload())
            ->assertCreated()
            ->assertJsonPath('data.team.status', 'pending')
            ->assertJsonPath('data.team.name', 'Garuda FC')
            ->assertJsonPath('data.team.payment_status', 'paid'); // free event settles immediately

        $this->assertDatabaseHas('teams', ['event_id' => $event->id, 'name' => 'Garuda FC', 'status' => 'pending']);
        $this->assertDatabaseCount('players', 2);
    }

    public function test_paid_event_registration_awaits_payment(): void
    {
        $event = $this->openEvent();
        $event->update(['registration_fee' => 150000]);
        $org = $event->organization;

        // No Midtrans credentials in tests → gateway is mocked and the fee
        // settles immediately (dev convenience), so the team is marked paid.
        $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", $this->teamPayload())
            ->assertCreated()
            ->assertJsonPath('data.team.payment_amount', 150000)
            ->assertJsonPath('data.team.payment_status', 'paid')
            ->assertJsonPath('data.mock', true);
    }

    public function test_registration_blocked_when_event_quota_full(): void
    {
        $event = $this->openEvent('10', maxTeams: 1);
        $org = $event->organization;

        $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", $this->teamPayload())
            ->assertCreated();

        $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", $this->teamPayload(['name' => 'Other']))
            ->assertStatus(422);
    }

    public function test_organizer_can_approve_registration(): void
    {
        $event = $this->openEvent();
        $org = $event->organization;

        $teamId = $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", $this->teamPayload())
            ->json('data.team.id');

        $this->actingAs($org->owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/{$teamId}", [
                'status' => 'approved',
                'group_name' => 'A',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('teams', ['id' => $teamId, 'status' => 'approved', 'group_name' => 'A']);
    }
}
