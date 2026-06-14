<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MyTeamTest extends TestCase
{
    use RefreshDatabase;

    private function openEvent(float $fee = 0): Event
    {
        $owner = User::factory()->create();
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);
        $plan->features()->create(['feature_key' => 'max_teams_per_event', 'value' => '10']);
        $org = Organization::create(['name' => 'EO', 'slug' => 'eo-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id]);

        return $org->events()->create([
            'name' => 'Cup', 'slug' => 'cup', 'sport_type' => 'football', 'tournament_format' => 'league',
            'status' => 'open', 'start_date' => '2026-08-01', 'end_date' => '2026-08-10',
            'registration_open' => Carbon::now()->subDay(), 'registration_close' => Carbon::now()->addDays(10),
            'registration_fee' => $fee,
        ]);
    }

    private function register(Event $event, User $user): string
    {
        $org = $event->organization;

        return $this->actingAs($user, 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", [
                'name' => 'Garuda FC',
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
                'players' => [
                    ['full_name' => 'Player One', 'jersey_number' => '10'],
                    ['full_name' => 'Player Two', 'jersey_number' => '7'],
                ],
            ])
            ->assertCreated()
            ->json('data.team.id');
    }

    public function test_participant_can_update_team_and_sync_roster(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent();
        $teamId = $this->register($event, $user);

        $playerId = $this->actingAs($user, 'api')->getJson("/api/v1/my-teams/{$teamId}")
            ->json('data.players.0.id');

        // Rename team, update one existing player, add a new one, drop the other.
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/my-teams/{$teamId}", [
                'name' => 'Garuda United',
                'players' => [
                    ['id' => $playerId, 'full_name' => 'Player One Edited', 'jersey_number' => '11'],
                    ['full_name' => 'Player Three', 'jersey_number' => '9'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Garuda United');

        $this->assertDatabaseHas('teams', ['id' => $teamId, 'name' => 'Garuda United']);
        $this->assertDatabaseHas('players', ['id' => $playerId, 'full_name' => 'Player One Edited']);
        $this->assertDatabaseHas('players', ['team_id' => $teamId, 'full_name' => 'Player Three']);
        $this->assertDatabaseMissing('players', ['team_id' => $teamId, 'full_name' => 'Player Two']);
        $this->assertDatabaseCount('players', 2);
    }

    public function test_participant_can_withdraw_team(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent();
        $teamId = $this->register($event, $user);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/my-teams/{$teamId}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');

        $this->assertDatabaseHas('teams', ['id' => $teamId, 'status' => 'withdrawn']);
    }

    public function test_withdrawn_team_cannot_be_edited(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent();
        $teamId = $this->register($event, $user);

        $this->actingAs($user, 'api')->postJson("/api/v1/my-teams/{$teamId}/withdraw")->assertOk();

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/my-teams/{$teamId}", ['name' => 'Nope'])
            ->assertStatus(422);
    }

    public function test_participant_cannot_access_others_team(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = $this->openEvent();
        $teamId = $this->register($event, $owner);

        $this->actingAs($stranger, 'api')->getJson("/api/v1/my-teams/{$teamId}")->assertStatus(404);
    }
}
