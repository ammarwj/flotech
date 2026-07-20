<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who gets to make a result official.
 *
 * Whoever runs the organization signs off by the act of saving; an `operator`
 * only records, and their result waits. The two-step therefore costs a click
 * exactly when there really are two people, and nothing when there is one.
 */
class ResultConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    private function operatorFor(Organization $org): User
    {
        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        return $operator;
    }

    /** @param 'knockout_single'|'league' $format */
    private function event(Organization $org, string $format = 'knockout_single', int $teams = 8): Event
    {
        $event = $org->events()->create([
            'name' => 'Confirm Cup',
            'slug' => 'confirm-cup-'.uniqid(),
            'sport_type' => 'mini_soccer',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => $format,
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        foreach (range(1, $teams) as $i) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => 'Team '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'status' => 'approved',
                'contact_name' => 'PIC',
                'contact_phone' => '0800',
            ]);
        }

        return $event->load('categories');
    }

    private function generate(User $user, Organization $org, Event $event): void
    {
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated();
    }

    private function slot(Event $event, int $round, int $order): GameMatch
    {
        return $event->matches()->where('round', $round)->where('order', $order)->firstOrFail();
    }

    private function saveScore(User $actor, Organization $org, GameMatch $match, int $home, int $away)
    {
        return $this->actingAs($actor, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}", [
                'status' => 'finished', 'home_score' => $home, 'away_score' => $away,
            ]);
    }

    public function test_a_result_saved_by_the_owner_is_confirmed_and_advances(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $tie = $this->slot($event, 1, 0);
        $winner = $tie->home_team_id;

        $this->saveScore($user, $org, $tie, 2, 1)
            ->assertOk()
            ->assertJsonPath('data.confirmed', true);

        // One step, not two: the winner is already through.
        $this->assertSame($winner, $this->slot($event, 2, 0)->home_team_id);
    }

    public function test_a_result_saved_by_an_operator_waits_for_an_admin(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $operator = $this->operatorFor($org);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $tie = $this->slot($event, 1, 0);

        $this->saveScore($operator, $org, $tie, 2, 1)
            ->assertOk()
            ->assertJsonPath('data.confirmed', false);

        $this->assertNull($tie->fresh()->confirmed_at);
        $this->assertNull($this->slot($event, 2, 0)->home_team_id, 'nothing advances until it is signed off');
    }

    public function test_an_operator_cannot_confirm_a_result(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $operator = $this->operatorFor($org);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $tie = $this->slot($event, 1, 0);
        $this->saveScore($operator, $org, $tie, 2, 1)->assertOk();

        // Without this the auto-confirm distinction would be decorative — an
        // operator would just ratify their own scoreline.
        $this->actingAs($operator, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$tie->id}/confirm", ['confirmed' => true])
            ->assertStatus(403);

        $this->assertNull($tie->fresh()->confirmed_at);
    }

    public function test_an_admin_member_signs_off_like_the_owner(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $admin = User::factory()->create();
        $org->members()->create(['user_id' => $admin->id, 'role' => 'admin']);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $this->saveScore($admin, $org, $this->slot($event, 1, 0), 3, 0)
            ->assertOk()
            ->assertJsonPath('data.confirmed', true);
    }

    public function test_editing_a_confirmed_result_moves_the_winner_instead_of_stranding_the_old_one(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $tie = $this->slot($event, 1, 0);
        $home = $tie->home_team_id;
        $away = $tie->away_team_id;

        $this->saveScore($user, $org, $tie, 2, 1)->assertOk();
        $this->assertSame($home, $this->slot($event, 2, 0)->home_team_id);

        // Flip the result. The old winner must not stay upstairs.
        $this->saveScore($user, $org, $tie, 1, 2)->assertOk();

        $this->assertSame(
            $away,
            $this->slot($event, 2, 0)->home_team_id,
            'the corrected winner takes the slot',
        );
    }

    public function test_an_operator_editing_a_confirmed_result_withdraws_it(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $operator = $this->operatorFor($org);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $tie = $this->slot($event, 1, 0);
        $this->saveScore($user, $org, $tie, 2, 1)->assertOk();
        $this->assertNotNull($this->slot($event, 2, 0)->home_team_id);

        $this->saveScore($operator, $org, $tie, 1, 2)
            ->assertOk()
            ->assertJsonPath('data.confirmed', false);

        // An operator's correction un-ratifies the result, so the next round
        // empties until an admin signs the new scoreline off.
        $this->assertNull($this->slot($event, 2, 0)->home_team_id);
    }

    public function test_leaderboard_ignores_results_that_are_not_final(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $operator = $this->operatorFor($org);
        $event = $this->event($org, format: 'league', teams: 4);
        $this->generate($user, $org, $event);
        $category = $event->categories->first();

        $match = $event->matches()->firstOrFail();
        $player = $event->teams()->where('id', $match->home_team_id)->firstOrFail()
            ->players()->create(['full_name' => 'Striker', 'jersey_number' => 9]);

        // Operator records the score and the scorer — nothing is final yet.
        $this->saveScore($operator, $org, $match, 1, 0)->assertOk();
        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/matches/{$match->id}/stats", [
                'stats' => [['player_id' => $player->id, 'stat_key' => 'goals', 'value' => 1]],
            ])
            ->assertOk();

        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/leaderboard";

        $before = $this->actingAs($user, 'api')->getJson($url)->assertOk()->json('data.rows');
        $this->assertSame([], $before, 'a provisional goal must not reach the leaderboard');

        // An admin signs it off; now it counts.
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}/confirm", ['confirmed' => true])
            ->assertOk();

        $after = $this->actingAs($user, 'api')->getJson($url)->assertOk()->json('data.rows');
        $this->assertCount(1, $after);
        $this->assertSame('Striker', $after[0]['player_name']);
    }

    public function test_organization_payload_tells_the_dashboard_the_callers_role(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $operator = $this->operatorFor($org);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonPath('data.0.my_role', 'owner');

        $this->actingAs($operator, 'api')
            ->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonPath('data.0.my_role', 'operator');
    }
}
