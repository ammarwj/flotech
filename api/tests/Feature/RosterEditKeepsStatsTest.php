<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing a team must not cost it its match statistics.
 *
 * `player_match_stats.player_id` is `cascadeOnDelete`, and TeamRosterService
 * deletes any player the payload leaves out — so a roster round-trip that loses
 * the player ids silently destroys every goal that player ever scored.
 */
class RosterEditKeepsStatsTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    private function event(Organization $org): Event
    {
        $event = $org->events()->create([
            'name' => 'Roster Cup',
            'slug' => 'roster-cup-'.uniqid(),
            'sport_type' => 'mini_soccer',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        return $event->load('categories');
    }

    /**
     * A team with one player, entered the way the organizer does it, plus an
     * opponent and a played match in which the player scored.
     *
     * @return array{0: string, 1: string, 2: string} team id, player id, match id
     */
    private function seedTeamWithAGoal(User $user, Organization $org, Event $event): array
    {
        $categoryId = $event->categories->first()->id;
        $base = "/api/v1/organizations/{$org->id}/events/{$event->id}";

        $team = $this->actingAs($user, 'api')
            ->postJson("{$base}/registrations", [
                'category_id' => $categoryId,
                'name' => 'Garuda FC',
                'players' => [['full_name' => 'Striker', 'jersey_number' => '9']],
            ])
            ->assertCreated()
            ->json('data');

        $opponent = $this->actingAs($user, 'api')
            ->postJson("{$base}/registrations", [
                'category_id' => $categoryId,
                'name' => 'Rajawali United',
            ])
            ->assertCreated()
            ->json('data.id');

        $playerId = $team['players'][0]['id'];

        $matchId = $this->actingAs($user, 'api')
            ->postJson("{$base}/categories/{$categoryId}/matches", [
                'home_team_id' => $team['id'],
                'away_team_id' => $opponent,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$matchId}", [
                'status' => 'finished', 'home_score' => 1, 'away_score' => 0,
            ])
            ->assertOk();

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/matches/{$matchId}/stats", [
                'stats' => [['player_id' => $playerId, 'stat_key' => 'goals', 'value' => 1]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('player_match_stats', ['player_id' => $playerId, 'value' => 1]);

        return [$team['id'], $playerId, $matchId];
    }

    public function test_uploading_a_logo_keeps_the_players_and_their_stats(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        [$teamId, $playerId] = $this->seedTeamWithAGoal($user, $org, $event);

        // Exactly what the organizer dialog sends when all it changed is the
        // crest: the same roster, each row carrying the id it came back with.
        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/{$teamId}", [
                'category_id' => $event->categories->first()->id,
                'name' => 'Garuda FC',
                'logo_url' => 'https://cdn.example/crest.webp',
                'players' => [
                    ['id' => $playerId, 'full_name' => 'Striker', 'jersey_number' => '9'],
                ],
            ])
            ->assertOk();

        // The player must be the same row, not a look-alike replacement: a new
        // id means the old one was deleted and took its goals with it.
        $this->assertDatabaseHas('players', ['id' => $playerId]);
        $this->assertDatabaseHas('player_match_stats', ['player_id' => $playerId, 'value' => 1]);
    }

    public function test_the_leaderboard_still_shows_the_goal_after_an_edit(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        [$teamId, $playerId] = $this->seedTeamWithAGoal($user, $org, $event);
        $categoryId = $event->categories->first()->id;

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/{$teamId}", [
                'category_id' => $categoryId,
                'name' => 'Garuda FC',
                'logo_url' => 'https://cdn.example/crest.webp',
                'players' => [
                    ['id' => $playerId, 'full_name' => 'Striker', 'jersey_number' => '9'],
                ],
            ])
            ->assertOk();

        $rows = $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$categoryId}/leaderboard")
            ->assertOk()
            ->json('data.rows');

        $this->assertCount(1, $rows, 'the scorer disappeared from the leaderboard');
        $this->assertSame('Striker', $rows[0]['player_name']);
    }
}
