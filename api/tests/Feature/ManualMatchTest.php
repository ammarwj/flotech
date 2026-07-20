<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manual fixtures: organizers who already have their own schedule add matches by
 * hand instead of auto-generating. A manual league match flows into the standings
 * once its result is confirmed, exactly like a generated one.
 */
class ManualMatchTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    /**
     * A league event with the given number of approved teams (plus optionally one
     * pending team, which must not be selectable for a fixture).
     */
    private function leagueEvent(Organization $org, int $approved = 2, bool $withPending = false): Event
    {
        $event = $org->events()->create([
            'name' => 'League Cup',
            'slug' => 'league-cup-'.uniqid(),
            'sport_type' => 'mini_soccer',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        foreach (range(1, $approved) as $i) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => 'Team '.$i,
                'status' => 'approved',
                'contact_name' => 'PIC',
                'contact_phone' => '0800',
            ]);
        }

        if ($withPending) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => 'Pending Team',
                'status' => 'pending',
                'contact_name' => 'PIC',
                'contact_phone' => '0800',
            ]);
        }

        return $event->load('categories');
    }

    private function matchesUrl(Organization $org, Event $event): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/matches";
    }

    public function test_manual_match_is_created_as_a_generic_fixture(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->leagueEvent($org);
        [$home, $away] = $event->teams()->orderBy('name')->pluck('id')->all();

        $this->actingAs($user, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $home,
                'away_team_id' => $away,
                'scheduled_at' => '2026-08-05T15:00:00Z',
                'venue' => 'Lapangan A',
            ])
            ->assertCreated()
            ->assertJsonPath('data.home_team.id', $home)
            ->assertJsonPath('data.away_team.id', $away)
            ->assertJsonPath('data.stage', null);

        $match = $event->matches()->first();
        $this->assertNull($match->stage);
        $this->assertSame('scheduled', $match->status);
        $this->assertSame('Lapangan A', $match->venue);
    }

    public function test_manual_match_rejects_teams_outside_the_approved_pool(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->leagueEvent($org, approved: 2, withPending: true);
        [$approvedHome] = $event->teams()->where('status', 'approved')->orderBy('name')->pluck('id')->all();
        $pending = $event->teams()->where('status', 'pending')->value('id');

        // A pending team can't be paired.
        $this->actingAs($user, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $approvedHome,
                'away_team_id' => $pending,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('away_team_id');

        // Home and away must differ.
        $this->actingAs($user, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $approvedHome,
                'away_team_id' => $approvedHome,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('away_team_id');
    }

    public function test_manual_match_needs_two_approved_teams(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->leagueEvent($org, approved: 1);
        $only = $event->teams()->value('id');

        $this->actingAs($user, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $only,
                'away_team_id' => $only,
            ])
            ->assertStatus(422);

        $this->assertSame(0, $event->matches()->count());
    }

    public function test_confirmed_manual_league_match_appears_in_the_standings(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->leagueEvent($org);
        [$home, $away] = $event->teams()->orderBy('name')->pluck('id')->all();
        $catId = $event->categories->first()->id;

        $matchId = $this->actingAs($user, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $home,
                'away_team_id' => $away,
            ])
            ->assertCreated()
            ->json('data.id');

        // Record and confirm a 2-1 home win.
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$matchId}", [
                'status' => 'finished', 'home_score' => 2, 'away_score' => 1,
            ])
            ->assertOk();
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$matchId}/confirm", ['confirmed' => true])
            ->assertOk();

        $rows = $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$catId}/standings")
            ->assertOk()
            ->json('data');

        $byTeam = collect($rows)->keyBy(fn ($row) => $row['team']['id']);
        $this->assertSame(1, $byTeam[$home]['played']);
        $this->assertSame(3, $byTeam[$home]['points']);
        $this->assertSame(0, $byTeam[$away]['points']);
    }

    public function test_delete_removes_the_match(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->leagueEvent($org);
        [$home, $away] = $event->teams()->orderBy('name')->pluck('id')->all();

        $matchId = $this->actingAs($user, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $home, 'away_team_id' => $away,
            ])
            ->json('data.id');

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/matches/{$matchId}")
            ->assertOk();

        $this->assertSame(0, $event->matches()->count());
    }

    public function test_delete_rejects_a_match_from_another_org(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->leagueEvent($org);
        [$home, $away] = $event->teams()->orderBy('name')->pluck('id')->all();

        $matchId = $this->actingAs($owner, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $home, 'away_team_id' => $away,
            ])
            ->json('data.id');

        // A second org's owner can't reach the first org's match.
        $intruder = User::factory()->create();
        $otherOrg = $this->org($intruder);

        $this->actingAs($intruder, 'api')
            ->deleteJson("/api/v1/organizations/{$otherOrg->id}/matches/{$matchId}")
            ->assertNotFound();

        $this->assertSame(1, $event->matches()->count());
    }

    /**
     * A hybrid event whose teams are already drawn into groups — the state a
     * manual group fixture needs to exist in.
     */
    private function hybridEvent(Organization $org): Event
    {
        $event = $org->events()->create([
            'name' => 'Hybrid Cup',
            'slug' => 'hybrid-cup-'.uniqid(),
            'sport_type' => 'mini_soccer',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'hybrid',
            'registration_fee' => 0,
            'sort_order' => 0,
            'bracket_config' => ['groups' => 2, 'teams_per_group' => 2],
        ]);

        foreach (['A', 'A', 'B', 'B'] as $i => $group) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => "Team {$group}".($i + 1),
                'group_name' => $group,
                'status' => 'approved',
                'contact_name' => 'PIC',
                'contact_phone' => '0800',
            ]);
        }

        return $event->load('categories');
    }

    public function test_a_manual_fixture_can_join_a_group_and_reach_its_table(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);
        [$home, $away] = $event->teams()->where('group_name', 'A')->pluck('id')->all();

        $matchId = $this->actingAs($user, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $home,
                'away_team_id' => $away,
                'group_name' => 'A',
            ])
            ->assertCreated()
            ->assertJsonPath('data.stage', 'group')
            ->assertJsonPath('data.group_name', 'A')
            ->json('data.id');

        // The point of naming a group: the result counts in that group's table,
        // which a stage-null fixture never would.
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$matchId}", [
                'status' => 'finished', 'home_score' => 3, 'away_score' => 0,
            ])
            ->assertOk();

        $rows = $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/standings")
            ->assertOk()
            ->json('data');

        $winner = collect($rows)->firstWhere('team.id', $home);
        $this->assertSame(1, $winner['played']);
        $this->assertSame(3, $winner['points']);
        $this->assertSame('A', $winner['group_name']);
    }

    public function test_a_group_fixture_is_refused_when_the_teams_are_in_different_groups(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);
        $fromA = $event->teams()->where('group_name', 'A')->value('id');
        $fromB = $event->teams()->where('group_name', 'B')->value('id');

        // Otherwise the result would land in a table one of them doesn't play in.
        $this->actingAs($user, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $fromA,
                'away_team_id' => $fromB,
                'group_name' => 'A',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('group_name');

        $this->assertSame(0, $event->matches()->count());
    }

    public function test_an_unknown_group_is_refused(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);
        [$home, $away] = $event->teams()->where('group_name', 'A')->pluck('id')->all();

        // Only the groups the config actually defines (2 groups → A and B).
        $this->actingAs($user, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $home,
                'away_team_id' => $away,
                'group_name' => 'Z',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('group_name');
    }

    public function test_a_league_fixture_cannot_be_given_a_group(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->leagueEvent($org);
        [$home, $away] = $event->teams()->orderBy('name')->pluck('id')->all();

        // A league has no groups at all, so there is nothing valid to send.
        $this->actingAs($user, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $home,
                'away_team_id' => $away,
                'group_name' => 'A',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('group_name');
    }
}
