<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use App\Services\StandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Group + Knockout (hybrid): draw, group fixtures, group table, and the
 * automatic bracket built from the qualifiers.
 */
class HybridFormatTest extends TestCase
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
     * @param  array<string, mixed>  $config
     */
    private function hybridEvent(Organization $org, array $config = [], int $teams = 8): Event
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
            'bracket_config' => array_merge([
                'groups' => 2,
                'teams_per_group' => 4,
                'home_away' => false,
                'points' => ['win' => 3, 'draw' => 1, 'lose' => 0],
                'qualification' => ['top_per_group' => 2],
                'draw_method' => 'random',
            ], $config),
        ]);

        foreach (range(1, $teams) as $i) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => 'Team '.$i,
                'status' => 'approved',
                'contact_name' => 'PIC',
                'contact_phone' => '0800',
            ]);
        }

        return $event->load('categories');
    }

    /** Play every group fixture: home team wins, then confirm. */
    private function finishGroupStage(Event $event): void
    {
        foreach ($event->matches()->where('stage', 'group')->get() as $m) {
            $m->update([
                'home_score' => 2,
                'away_score' => 1,
                'status' => 'finished',
                'confirmed_at' => now(),
            ]);
        }
    }

    public function test_generate_draws_groups_and_builds_group_fixtures(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);

        // 2 groups × 4 teams → 6 fixtures per group, 12 total.
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated()
            ->assertJsonCount(12, 'data');

        $this->assertSame(4, $event->teams()->where('group_name', 'A')->count());
        $this->assertSame(4, $event->teams()->where('group_name', 'B')->count());
        $this->assertSame(12, $event->matches()->where('stage', 'group')->count());

        // Nobody plays outside their own group.
        foreach ($event->matches()->with(['homeTeam', 'awayTeam'])->get() as $m) {
            $this->assertSame($m->homeTeam->group_name, $m->awayTeam->group_name);
            $this->assertSame($m->group_name, $m->homeTeam->group_name);
        }
    }

    public function test_home_and_away_doubles_the_group_fixtures(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org, ['home_away' => true]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated()
            ->assertJsonCount(24, 'data');

        $this->assertSame(12, $event->matches()->where('leg', 2)->count());
    }

    public function test_manual_draw_places_teams_in_the_chosen_groups(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);

        $ids = $event->teams()->orderBy('name')->pluck('id')->all();
        $assignments = [];
        foreach ($ids as $i => $id) {
            $assignments[$id] = $i < 4 ? 'A' : 'B';
        }

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/draw", [
                'method' => 'manual',
                'assignments' => $assignments,
            ])
            ->assertOk();

        $this->assertSame('A', Team::find($ids[0])->group_name);
        $this->assertSame('B', Team::find($ids[7])->group_name);
    }

    public function test_standings_use_configured_points_and_are_grouped(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org, ['points' => ['win' => 2, 'draw' => 1, 'lose' => 0]]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated();

        $this->finishGroupStage($event);

        $rows = $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/standings")
            ->assertOk()
            ->json('data');

        $this->assertCount(8, $rows);
        $this->assertContains($rows[0]['group_name'], ['A', 'B']);

        // Every team played 3 group games; 2 points a win means at most 6.
        foreach ($rows as $row) {
            $this->assertSame(3, $row['played']);
            $this->assertSame($row['won'] * 2 + $row['drawn'], $row['points']);
        }
    }

    /**
     * The group table stands on the draw alone — the organizer web pages render
     * it before any fixture exists, so an empty schedule must not empty it.
     */
    public function test_standings_are_returned_for_a_drawn_group_with_no_fixtures(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);
        $category = $event->categories->first();

        $ids = $event->teams()->orderBy('name')->pluck('id')->all();
        $assignments = [];
        foreach ($ids as $i => $id) {
            $assignments[$id] = $i < 4 ? 'A' : 'B';
        }

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/draw", [
                'method' => 'manual',
                'assignments' => $assignments,
            ])
            ->assertOk();

        // Drawn, but deliberately no schedule generated.
        $this->assertSame(0, $event->matches()->count());

        $rows = $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/standings")
            ->assertOk()
            ->json('data');

        $this->assertCount(8, $rows);
        foreach ($rows as $row) {
            $this->assertContains($row['group_name'], ['A', 'B']);
            $this->assertSame(0, $row['played']);
            $this->assertSame(0, $row['points']);
        }
    }

    public function test_knockout_is_refused_when_the_group_stage_has_no_fixtures(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);
        $category = $event->categories->first();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/draw", [
                'method' => 'random',
            ])
            ->assertOk();

        // Nothing pending, because nothing exists — the bracket must not read
        // that as a finished group stage and seed itself alphabetically.
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/knockout")
            ->assertStatus(422);

        $this->assertSame(0, $event->matches()->where('stage', 'knockout')->count());

        // Contrast: the same call succeeds once the groups are actually played.
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/schedule")
            ->assertCreated();
        $this->finishGroupStage($event);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/knockout")
            ->assertCreated();
        $this->assertSame(3, $event->matches()->where('stage', 'knockout')->count());
    }

    public function test_knockout_needs_the_group_stage_to_be_finished(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/knockout")
            ->assertStatus(422)
            ->assertJsonPath('errors.feature', 'group_stage_incomplete');
    }

    public function test_bracket_plan_is_available_before_the_groups_finish(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated();

        // Not a single result yet — the plan still knows who meets whom.
        $plan = $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/knockout-plan")
            ->assertOk()
            ->json('data');

        $this->assertSame(4, $plan['bracket_size']);   // 2 groups × top 2
        $this->assertSame(12, $plan['group_matches_pending']);
        $this->assertCount(2, $plan['ties']);

        // Semifinals pair a group winner against the other group's runner-up.
        $labels = array_map(
            fn ($tie) => [$tie['home']['label'], $tie['away']['label']],
            $plan['ties'],
        );

        $this->assertContains(['Juara Grup A', 'Runner-up Grup B'], $labels);
        $this->assertContains(['Juara Grup B', 'Runner-up Grup A'], $labels);
    }

    public function test_knockout_bracket_can_be_deleted_without_touching_the_groups(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);
        $category = $event->categories->first();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/schedule")
            ->assertCreated();
        $this->finishGroupStage($event);
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/knockout")
            ->assertCreated();

        $groupsBefore = $event->matches()->where('stage', 'group')->count();

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/knockout")
            ->assertOk();

        $this->assertSame(0, $event->matches()->where('stage', 'knockout')->count());
        // The whole point of the undo: group results survive it.
        $this->assertSame($groupsBefore, $event->matches()->where('stage', 'group')->count());

        // Nothing left to delete → refused, not a silent no-op.
        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/knockout")
            ->assertStatus(422);

        // And the bracket can be rebuilt afterwards.
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/knockout")
            ->assertCreated();
        $this->assertSame(3, $event->matches()->where('stage', 'knockout')->count());
    }

    public function test_knockout_bracket_is_built_from_the_qualifiers(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated();
        $this->finishGroupStage($event);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/knockout")
            ->assertCreated();

        // 2 groups × top 2 = 4 qualifiers → semifinals + final.
        $knockout = $event->matches()->where('stage', 'knockout')->get();
        $this->assertCount(3, $knockout);
        $this->assertSame(2, $knockout->where('round', 1)->count());
        $this->assertSame(12, $event->matches()->where('stage', 'group')->count()); // groups untouched

        // Group winners are kept apart in the first round.
        foreach ($knockout->where('round', 1) as $m) {
            $this->assertNotNull($m->home_team_id);
            $this->assertNotNull($m->away_team_id);
            $this->assertNotSame(
                Team::find($m->home_team_id)->group_name,
                Team::find($m->away_team_id)->group_name,
            );
        }
    }

    public function test_odd_qualifier_count_gets_a_bye_into_the_next_round(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        // 3 groups of 3, only the winners qualify → 3 teams in a bracket of 4.
        $event = $this->hybridEvent($org, [
            'groups' => 3,
            'teams_per_group' => 3,
            'qualification' => ['top_per_group' => 1],
        ], teams: 9);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated();
        $this->finishGroupStage($event);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/knockout")
            ->assertCreated();

        $bye = $event->matches()
            ->where('stage', 'knockout')
            ->where('round', 1)
            ->whereNull('away_team_id')
            ->first();

        $this->assertNotNull($bye, 'expected a bye in the first knockout round');
        $this->assertSame('finished', $bye->status);
        $this->assertNotNull($bye->confirmed_at);

        // The team with the bye is already through to the final.
        $final = $event->matches()->where('stage', 'knockout')->where('round', 2)->first();
        $this->assertContains($bye->home_team_id, [$final->home_team_id, $final->away_team_id]);
    }

    public function test_confirming_a_knockout_result_advances_the_winner(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated();
        $this->finishGroupStage($event);
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/knockout")
            ->assertCreated();

        /** @var GameMatch $semi */
        $semi = $event->matches()->where('stage', 'knockout')->where('round', 1)->orderBy('order')->first();

        // A knockout tie may not end level.
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$semi->id}", [
                'status' => 'finished', 'home_score' => 1, 'away_score' => 1,
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$semi->id}", [
                'status' => 'finished', 'home_score' => 3, 'away_score' => 1,
            ])
            ->assertOk();

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$semi->id}/confirm", ['confirmed' => true])
            ->assertOk();

        $final = $event->matches()->where('stage', 'knockout')->where('round', 2)->first();
        $this->assertSame($semi->home_team_id, $final->home_team_id);
    }

    public function test_level_knockout_tie_is_settled_on_penalties(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated();
        $this->finishGroupStage($event);
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/knockout")
            ->assertCreated();

        /** @var GameMatch $semi */
        $semi = $event->matches()->where('stage', 'knockout')->where('round', 1)->orderBy('order')->first();
        $url = "/api/v1/organizations/{$org->id}/matches/{$semi->id}";

        // Level, no shootout → rejected.
        $this->actingAs($user, 'api')
            ->patchJson($url, ['status' => 'finished', 'home_score' => 1, 'away_score' => 1])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['home_penalty']]);

        // A shootout can't end level either.
        $this->actingAs($user, 'api')
            ->patchJson($url, [
                'status' => 'finished', 'home_score' => 1, 'away_score' => 1,
                'home_penalty' => 3, 'away_penalty' => 3,
            ])
            ->assertStatus(422);

        // 1-1, away wins 5-4 on penalties.
        $this->actingAs($user, 'api')
            ->patchJson($url, [
                'status' => 'finished', 'home_score' => 1, 'away_score' => 1,
                'home_penalty' => 4, 'away_penalty' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.away_penalty', 5);

        $this->actingAs($user, 'api')
            ->patchJson("{$url}/confirm", ['confirmed' => true])
            ->assertOk();

        // The shootout winner is the one who goes through.
        $final = $event->matches()->where('stage', 'knockout')->where('round', 2)->first();
        $this->assertSame($semi->away_team_id, $final->home_team_id);
    }

    public function test_penalties_are_dropped_when_the_score_is_decisive(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated();

        // Group games never go to penalties, even if a shootout is sent.
        $match = $event->matches()->where('stage', 'group')->first();

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}", [
                'status' => 'finished', 'home_score' => 2, 'away_score' => 2,
                'home_penalty' => 5, 'away_penalty' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('data.home_penalty', null);
    }

    public function test_assists_cannot_outnumber_a_teams_goals(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated();

        /** @var GameMatch $match */
        $match = $event->matches()->where('stage', 'group')->first();
        $match->update(['home_score' => 1, 'away_score' => 0, 'status' => 'finished']);

        $players = Team::find($match->home_team_id)->players()->createMany([
            ['full_name' => 'Pemain A', 'jersey_number' => '9'],
            ['full_name' => 'Pemain B', 'jersey_number' => '10'],
        ]);

        $stats = fn (int $assists) => [
            'stats' => [
                ['player_id' => $players[0]->id, 'stat_key' => 'goals', 'value' => 1],
                ['player_id' => $players[1]->id, 'stat_key' => 'assists', 'value' => $assists],
            ],
        ];

        // 2 assists on a 1-goal team is impossible.
        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/matches/{$match->id}/stats", $stats(2))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['assists']]);

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/matches/{$match->id}/stats", $stats(1))
            ->assertOk();
    }

    public function test_group_matches_may_end_in_a_draw(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->hybridEvent($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated();

        $match = $event->matches()->where('stage', 'group')->first();

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}", [
                'status' => 'finished', 'home_score' => 2, 'away_score' => 2,
            ])
            ->assertOk();
    }

    /**
     * A hybrid event whose groups have all been played, ready for the bracket.
     *
     * @return array{0: Event, 1: array<int, string>} the event and its qualifiers, best seed first
     */
    private function readyForKnockout(User $user, Organization $org, array $config = [], int $teams = 8): array
    {
        $event = $this->hybridEvent($org, $config, $teams);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated();
        $this->finishGroupStage($event);

        $qualifiers = app(StandingService::class)->qualifiers($event->categories->first());

        return [$event, array_values($qualifiers)];
    }

    private function knockoutUrl(Organization $org, Event $event): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/knockout";
    }

    public function test_manual_seeding_uses_the_organizer_pairings(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        [$event, $q] = $this->readyForKnockout($user, $org);

        // Automatic seeding mirrors 1-v-4 and 2-v-3; pairing the top two seeds
        // against each other is something it would never produce, so a bracket
        // that comes back this way can only have honoured the payload.
        $this->actingAs($user, 'api')
            ->postJson($this->knockoutUrl($org, $event), [
                'seeding' => 'manual',
                'pairs' => [
                    ['order' => 0, 'home_team_id' => $q[0], 'away_team_id' => $q[1]],
                    ['order' => 1, 'home_team_id' => $q[2], 'away_team_id' => $q[3]],
                ],
            ])
            ->assertCreated();

        $round1 = $event->matches()->where('stage', 'knockout')->where('round', 1)->orderBy('order')->get();

        $this->assertCount(2, $round1);
        $this->assertSame($q[0], $round1[0]->home_team_id);
        $this->assertSame($q[1], $round1[0]->away_team_id);
        $this->assertSame($q[2], $round1[1]->home_team_id);
        $this->assertSame($q[3], $round1[1]->away_team_id);
    }

    public function test_manual_seeding_rejects_a_team_that_did_not_qualify(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        [$event, $q] = $this->readyForKnockout($user, $org);

        $eliminated = $event->teams()->whereNotIn('id', $q)->value('id');
        $this->assertNotNull($eliminated);

        $this->actingAs($user, 'api')
            ->postJson($this->knockoutUrl($org, $event), [
                'seeding' => 'manual',
                'pairs' => [
                    ['order' => 0, 'home_team_id' => $eliminated, 'away_team_id' => $q[1]],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pairs.0.home_team_id');

        $this->assertSame(0, $event->matches()->where('stage', 'knockout')->count());
    }

    public function test_manual_seeding_rejects_a_duplicated_team(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        [$event, $q] = $this->readyForKnockout($user, $org);

        $this->actingAs($user, 'api')
            ->postJson($this->knockoutUrl($org, $event), [
                'seeding' => 'manual',
                'pairs' => [
                    ['order' => 0, 'home_team_id' => $q[0], 'away_team_id' => $q[1]],
                    ['order' => 1, 'home_team_id' => $q[0], 'away_team_id' => $q[3]],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pairs');

        $this->assertSame(0, $event->matches()->where('stage', 'knockout')->count());
    }

    public function test_manual_seeding_bye_is_auto_finished_and_advanced(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        // 3 groups of 3, winners only → 3 qualifiers in a bracket of 4.
        [$event, $q] = $this->readyForKnockout($user, $org, [
            'groups' => 3,
            'teams_per_group' => 3,
            'qualification' => ['top_per_group' => 1],
        ], teams: 9);

        $this->assertCount(3, $q);

        $this->actingAs($user, 'api')
            ->postJson($this->knockoutUrl($org, $event), [
                'seeding' => 'manual',
                'pairs' => [
                    ['order' => 0, 'home_team_id' => $q[2], 'away_team_id' => null],
                    ['order' => 1, 'home_team_id' => $q[0], 'away_team_id' => $q[1]],
                ],
            ])
            ->assertCreated();

        // The organizer chose who gets the bye — not the top seed, as auto would.
        $bye = $event->matches()->where('stage', 'knockout')->where('round', 1)->where('order', 0)->first();
        $this->assertSame($q[2], $bye->home_team_id);
        $this->assertNull($bye->away_team_id);
        $this->assertSame('finished', $bye->status);
        $this->assertNotNull($bye->confirmed_at);

        // Byes advance on generation, manual seeding included.
        $final = $event->matches()->where('stage', 'knockout')->where('round', 2)->first();
        $this->assertSame($q[2], $final->home_team_id);
    }

    public function test_partial_manual_seeding_fills_the_rest_of_the_field(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        [$event, $q] = $this->readyForKnockout($user, $org);

        // Only the one tie the organizer cares about.
        $this->actingAs($user, 'api')
            ->postJson($this->knockoutUrl($org, $event), [
                'seeding' => 'manual',
                'pairs' => [
                    ['order' => 1, 'home_team_id' => $q[0], 'away_team_id' => $q[1]],
                ],
            ])
            ->assertCreated();

        $round1 = $event->matches()->where('stage', 'knockout')->where('round', 1)->orderBy('order')->get();

        $this->assertSame($q[0], $round1[1]->home_team_id);
        $this->assertSame($q[1], $round1[1]->away_team_id);
        // The two teams left over land in the slot that was left blank.
        $this->assertEqualsCanonicalizing(
            [$q[2], $q[3]],
            [$round1[0]->home_team_id, $round1[0]->away_team_id],
        );
    }

    public function test_swapping_two_seeds_keeps_the_rest_of_the_bracket(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        [$event] = $this->readyForKnockout($user, $org);

        $this->actingAs($user, 'api')->postJson($this->knockoutUrl($org, $event))->assertCreated();

        $ties = $event->matches()->where('stage', 'knockout')->where('round', 1)->orderBy('order')->get();
        [$first, $second] = [$ties[0], $ties[1]];
        $mine = $first->home_team_id;
        $theirs = $second->home_team_id;

        // Every qualifier is already placed, so correcting a seed in a hybrid
        // bracket always means exchanging two of them.
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$first->id}/teams", [
                'home_team_id' => $theirs,
                'away_team_id' => $first->away_team_id,
            ])
            ->assertOk();

        $this->assertSame($theirs, $first->fresh()->home_team_id);
        $this->assertSame($mine, $second->fresh()->home_team_id);
        // The group stage is none of this endpoint's business.
        $this->assertSame(12, $event->matches()->where('stage', 'group')->count());
    }

    public function test_auto_seeding_is_unchanged_when_asked_for_explicitly(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        [$event, $q] = $this->readyForKnockout($user, $org);

        $this->actingAs($user, 'api')
            ->postJson($this->knockoutUrl($org, $event), ['seeding' => 'auto'])
            ->assertCreated();

        // Same invariant the no-payload path asserts: group mates stay apart.
        foreach ($event->matches()->where('stage', 'knockout')->where('round', 1)->get() as $m) {
            $this->assertNotNull($m->away_team_id);
            $this->assertNotSame(
                Team::find($m->home_team_id)->group_name,
                Team::find($m->away_team_id)->group_name,
            );
        }
    }
}
