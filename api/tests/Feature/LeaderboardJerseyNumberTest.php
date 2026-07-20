<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The leaderboard carries the shirt number, and still collapses a player who
 * played twice into one row.
 *
 * `players.jersey_number` had to join the `groupBy` to be selectable — the risk
 * that buys is a stray column splitting one scorer into several rows, so the
 * summing case is tested next to the number itself.
 */
class LeaderboardJerseyNumberTest extends TestCase
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
            'name' => 'Jersey Cup',
            'slug' => 'jersey-cup-'.uniqid(),
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

    /** @param array<int, array<string, string|null>> $players */
    private function team(User $user, Organization $org, Event $event, string $name, array $players = []): array
    {
        return $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations", [
                'category_id' => $event->categories->first()->id,
                'name' => $name,
                'players' => $players,
            ])
            ->assertCreated()
            ->json('data');
    }

    /** Plays a finished match and records the given goals. */
    private function playMatch(User $user, Organization $org, Event $event, string $home, string $away, int $homeScore, array $stats): void
    {
        $categoryId = $event->categories->first()->id;

        $matchId = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$categoryId}/matches", [
                'home_team_id' => $home,
                'away_team_id' => $away,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$matchId}", [
                'status' => 'finished', 'home_score' => $homeScore, 'away_score' => 0,
            ])
            ->assertOk();

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/matches/{$matchId}/stats", ['stats' => $stats])
            ->assertOk();
    }

    public function test_leaderboard_carries_the_jersey_number_and_tolerates_a_missing_one(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $categoryId = $event->categories->first()->id;

        $garuda = $this->team($user, $org, $event, 'Garuda FC', [
            ['full_name' => 'Budi Santoso', 'jersey_number' => '10'],
            // The roster lets a shirt go unfilled; the leaderboard must not choke.
            ['full_name' => 'Rizky Pratama'],
        ]);
        $rajawali = $this->team($user, $org, $event, 'Rajawali United');

        [$budi, $rizky] = $garuda['players'];

        $this->playMatch($user, $org, $event, $garuda['id'], $rajawali['id'], 3, [
            ['player_id' => $budi['id'], 'stat_key' => 'goals', 'value' => 2],
            ['player_id' => $rizky['id'], 'stat_key' => 'goals', 'value' => 1],
        ]);

        $rows = $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$categoryId}/leaderboard")
            ->assertOk()
            ->json('data.rows');

        $byName = collect($rows)->keyBy('player_name');

        $this->assertSame('10', $byName['Budi Santoso']['jersey_number']);
        $this->assertNull($byName['Rizky Pratama']['jersey_number'], 'a player without a shirt must come back null, not "" or 0');
    }

    public function test_a_scorer_in_two_matches_is_still_one_row(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $categoryId = $event->categories->first()->id;

        $garuda = $this->team($user, $org, $event, 'Garuda FC', [
            ['full_name' => 'Budi Santoso', 'jersey_number' => '10'],
        ]);
        $rajawali = $this->team($user, $org, $event, 'Rajawali United');
        $elang = $this->team($user, $org, $event, 'Elang FC');

        $budi = $garuda['players'][0]['id'];

        $this->playMatch($user, $org, $event, $garuda['id'], $rajawali['id'], 2, [
            ['player_id' => $budi, 'stat_key' => 'goals', 'value' => 2],
        ]);
        $this->playMatch($user, $org, $event, $garuda['id'], $elang['id'], 3, [
            ['player_id' => $budi, 'stat_key' => 'goals', 'value' => 3],
        ]);

        $rows = $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$categoryId}/leaderboard")
            ->assertOk()
            ->json('data.rows');

        $this->assertCount(1, $rows, 'the scorer was split across rows — check the groupBy');
        $this->assertSame(5, $rows[0]['stats']['goals']);
        $this->assertSame('10', $rows[0]['jersey_number']);
    }
}
