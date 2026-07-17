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
}
