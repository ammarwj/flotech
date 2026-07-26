<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The bench — pelatih, manajer, ofisial — lives in its own table so it can be
 * filled in without disturbing anything that reads the roster. These tests are
 * mostly about that separation: the interesting assertions are the ones showing
 * a coach does *not* count as a player.
 */
class TeamOfficialTest extends TestCase
{
    use RefreshDatabase;

    private function openEvent(string $participantType = 'team', string $sport = 'football'): Event
    {
        $owner = User::factory()->create();
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);
        $plan->features()->create(['feature_key' => 'max_teams_per_event', 'value' => '10']);
        $org = Organization::create(['name' => 'EO', 'slug' => 'eo-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id]);

        $event = $org->events()->create([
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => $sport,
            'status' => 'open', 'start_date' => '2026-08-01', 'end_date' => '2026-08-10',
            'registration_open' => Carbon::now()->subDay(), 'registration_close' => Carbon::now()->addDays(10),
        ]);

        $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league',
            'participant_type' => $participantType, 'registration_fee' => 0, 'sort_order' => 0,
        ]);

        return $event->load(['categories', 'organization']);
    }

    private function registerUrl(Event $event): string
    {
        return "/api/v1/public/events/{$event->organization->slug}/{$event->slug}/register";
    }

    public function test_a_team_can_register_with_a_bench(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent();

        $teamId = $this->actingAs($user, 'api')
            ->postJson($this->registerUrl($event), [
                'category_id' => $event->categories->first()->id,
                'name' => 'Garuda FC',
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
                'players' => [['full_name' => 'Player One', 'jersey_number' => '10']],
                'officials' => [
                    ['full_name' => 'Coach Budi', 'role' => 'head_coach'],
                    // A role is optional; a name is not.
                    ['full_name' => 'Pak Joko'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.team.officials.0.full_name', 'Coach Budi')
            ->assertJsonPath('data.team.officials.0.role', 'head_coach')
            ->assertJsonPath('data.team.officials.1.role', null)
            ->json('data.team.id');

        $this->assertDatabaseHas('team_officials', ['team_id' => $teamId, 'full_name' => 'Coach Budi', 'role' => 'head_coach']);
        // The bench is not the roster: one player was sent, one player exists.
        $this->assertDatabaseCount('players', 1);
        $this->assertDatabaseCount('team_officials', 2);
    }

    public function test_a_role_must_exist_in_the_sports_master(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent();

        $this->actingAs($user, 'api')
            ->postJson($this->registerUrl($event), [
                'category_id' => $event->categories->first()->id,
                'name' => 'Garuda FC',
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
                'officials' => [['full_name' => 'Coach Budi', 'role' => 'kepala_suku']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('officials.0.role');

        $this->assertDatabaseCount('team_officials', 0);
    }

    /**
     * The whole reason officials aren't Players: a doubles entry is exactly two
     * players and is *named* after them. A coach must not be counted into either
     * rule — assert both, or this passes for the wrong reason.
     */
    public function test_a_bench_does_not_disturb_a_doubles_entry(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent('double', 'badminton');

        $this->actingAs($user, 'api')
            ->postJson($this->registerUrl($event), [
                'category_id' => $event->categories->first()->id,
                'name' => 'Pasangan 1',
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
                'players' => [['full_name' => 'Dimas'], ['full_name' => 'Ammar']],
                'officials' => [
                    ['full_name' => 'Coach Budi', 'role' => 'head_coach'],
                    ['full_name' => 'Manajer Sari', 'role' => 'team_manager'],
                ],
            ])
            ->assertCreated()
            // Not "Dimas / Ammar / Coach Budi / Manajer Sari".
            ->assertJsonPath('data.team.name', 'Dimas / Ammar');

        $this->assertDatabaseCount('players', 2);
        $this->assertDatabaseCount('team_officials', 2);
    }

    public function test_participant_can_sync_the_bench(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent();

        $teamId = $this->actingAs($user, 'api')
            ->postJson($this->registerUrl($event), [
                'category_id' => $event->categories->first()->id,
                'name' => 'Garuda FC',
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
                'officials' => [
                    ['full_name' => 'Coach Budi', 'role' => 'head_coach'],
                    ['full_name' => 'Pak Joko', 'role' => 'official'],
                ],
            ])
            ->assertCreated()
            ->json('data.team.id');

        $coachId = $this->actingAs($user, 'api')->getJson("/api/v1/my-teams/{$teamId}")
            ->json('data.officials.0.id');

        // Update the one carrying an id, add a new row, drop the one left out.
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/my-teams/{$teamId}", [
                'officials' => [
                    ['id' => $coachId, 'full_name' => 'Coach Budi Santoso', 'role' => 'head_coach'],
                    ['full_name' => 'Fisio Rina', 'role' => 'physio'],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('team_officials', ['id' => $coachId, 'full_name' => 'Coach Budi Santoso']);
        $this->assertDatabaseHas('team_officials', ['team_id' => $teamId, 'full_name' => 'Fisio Rina']);
        $this->assertDatabaseMissing('team_officials', ['team_id' => $teamId, 'full_name' => 'Pak Joko']);
        $this->assertDatabaseCount('team_officials', 2);
    }

    /**
     * A payload that says nothing about the bench must leave it alone — the key
     * being absent is not the same as it being empty, and a client that predates
     * this feature sends no key at all.
     */
    public function test_omitting_the_bench_does_not_clear_it(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent();

        $teamId = $this->actingAs($user, 'api')
            ->postJson($this->registerUrl($event), [
                'category_id' => $event->categories->first()->id,
                'name' => 'Garuda FC',
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
                'officials' => [['full_name' => 'Coach Budi', 'role' => 'head_coach']],
            ])
            ->assertCreated()
            ->json('data.team.id');

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/my-teams/{$teamId}", ['name' => 'Garuda United'])
            ->assertOk();

        $this->assertDatabaseCount('team_officials', 1);

        // An empty array, on the other hand, does mean "clear it".
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/my-teams/{$teamId}", ['officials' => []])
            ->assertOk();

        $this->assertDatabaseCount('team_officials', 0);
    }

    public function test_a_role_still_held_by_an_official_cannot_be_dropped(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $event = $this->openEvent();

        $this->actingAs($user, 'api')
            ->postJson($this->registerUrl($event), [
                'category_id' => $event->categories->first()->id,
                'name' => 'Garuda FC',
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
                'officials' => [['full_name' => 'Coach Budi', 'role' => 'head_coach']],
            ])
            ->assertCreated();

        $sport = Sport::where('slug', 'football')->firstOrFail();
        $url = "/api/v1/admin/sports/{$sport->id}/official-roles";

        // Dropping head_coach would leave Coach Budi holding a role nobody names.
        $this->actingAs($admin, 'api')
            ->putJson($url, ['official_roles' => [['role_key' => 'team_manager', 'label' => 'Manajer Tim']]])
            ->assertStatus(422);

        $this->assertDatabaseHas('sport_official_roles', ['sport_id' => $sport->id, 'role_key' => 'head_coach']);

        // Renaming the label is the supported move, and it reaches every team.
        $this->actingAs($admin, 'api')
            ->putJson($url, [
                'official_roles' => [
                    ['role_key' => 'head_coach', 'label' => 'Pelatih Utama'],
                    ['role_key' => 'team_manager', 'label' => 'Manajer Tim'],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('sport_official_roles', [
            'sport_id' => $sport->id, 'role_key' => 'head_coach', 'label' => 'Pelatih Utama',
        ]);
        // The team's stored key is untouched by the rename.
        $this->assertDatabaseHas('team_officials', ['full_name' => 'Coach Budi', 'role' => 'head_coach']);
    }
}
