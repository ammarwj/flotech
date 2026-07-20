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
 * Editing the teams in a first-round bracket slot: the fix for a wrong seed
 * that doesn't cost the organizer the results already played.
 *
 * The bracket has no next_match_id — advancement is computed from
 * (round, order) — so undoing an advancement means walking those same edges
 * backwards. Most of what is asserted here is that the walk stops where it
 * should.
 */
class BracketSlotEditTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    /** A single-elimination event with $teams approved teams. */
    private function knockoutEvent(Organization $org, int $teams = 8): Event
    {
        $event = $org->events()->create([
            'name' => 'Knockout Cup',
            'slug' => 'knockout-cup-'.uniqid(),
            'sport_type' => 'mini_soccer',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'knockout_single',
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

    /** An extra approved team that no generated bracket knows about yet. */
    private function spareTeam(Event $event, string $name = 'Late Entry'): string
    {
        return $event->teams()->create([
            'category_id' => $event->categories->first()->id,
            'name' => $name,
            'status' => 'approved',
            'contact_name' => 'PIC',
            'contact_phone' => '0800',
        ])->id;
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

    /** Play a 2-1 home win and confirm it, so the winner advances. */
    private function playAndConfirm(User $user, Organization $org, GameMatch $match): void
    {
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}", [
                'status' => 'finished', 'home_score' => 2, 'away_score' => 1,
            ])
            ->assertOk();

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}/confirm", ['confirmed' => true])
            ->assertOk();
    }

    private function teamsUrl(Organization $org, GameMatch $match): string
    {
        return "/api/v1/organizations/{$org->id}/matches/{$match->id}/teams";
    }

    public function test_manual_seeding_overrides_the_alphabetical_order(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, teams: 4);
        $ids = $event->teams()->orderBy('name')->pluck('id')->all();

        // Left alone, a single-elimination bracket seeds by team name, so
        // Team 01 v Team 02 in the opening tie can only come from the payload.
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule", [
                'seeding' => 'manual',
                'pairs' => [
                    ['order' => 0, 'home_team_id' => $ids[0], 'away_team_id' => $ids[1]],
                    ['order' => 1, 'home_team_id' => $ids[2], 'away_team_id' => $ids[3]],
                ],
            ])
            ->assertCreated();

        $first = $this->slot($event, 1, 0);
        $this->assertSame($ids[0], $first->home_team_id);
        $this->assertSame($ids[1], $first->away_team_id);
    }

    public function test_editing_a_round_one_slot_replaces_the_team(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);

        $spare = $this->spareTeam($event);
        $tie = $this->slot($event, 1, 0);
        $away = $tie->away_team_id;

        $this->actingAs($user, 'api')
            ->patchJson($this->teamsUrl($org, $tie), [
                'home_team_id' => $spare,
                'away_team_id' => $away,
            ])
            ->assertOk()
            ->assertJsonPath('data.home_team.id', $spare);

        $tie->refresh();
        $this->assertSame($spare, $tie->home_team_id);
        $this->assertSame($away, $tie->away_team_id);
        $this->assertSame('scheduled', $tie->status);
    }

    public function test_editing_a_slot_clears_the_confirmed_descendant(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        // 6 teams in a bracket of 8: the first two ties are byes, so both
        // feeders of the round-2 tie are already settled without a ball kicked
        // — which is the only way a still-editable seed can have advanced.
        $event = $this->knockoutEvent($org, teams: 6);
        $this->generate($user, $org, $event);
        $spare = $this->spareTeam($event);

        $parent = $this->slot($event, 2, 0);
        $fromEvenChild = $parent->home_team_id;
        $this->assertNotNull($fromEvenChild);
        $this->assertNotNull($parent->away_team_id);

        // Edit the odd-ordered child: it feeds the away slot only. Giving it a
        // real opponent ends the walkover, so nobody is through any more.
        $odd = $this->slot($event, 1, 1);
        $this->actingAs($user, 'api')
            ->patchJson($this->teamsUrl($org, $odd), [
                'home_team_id' => $odd->home_team_id,
                'away_team_id' => $spare,
            ])
            ->assertOk();

        $parent->refresh();
        $this->assertNull($parent->away_team_id, 'the edited seed should be withdrawn from the next round');
        $this->assertSame(
            $fromEvenChild,
            $parent->home_team_id,
            'the sibling half of the bracket must not be touched',
        );
    }

    public function test_editing_a_slot_resets_a_downstream_result(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, teams: 6);
        $this->generate($user, $org, $event);
        $spare = $this->spareTeam($event);

        // Two byes meet in round 2; that tie is played, so a team already sits
        // in the final while both round-1 seeds are still editable.
        $this->playAndConfirm($user, $org, $this->slot($event, 2, 0));
        $this->assertNotNull($this->slot($event, 3, 0)->home_team_id);

        $odd = $this->slot($event, 1, 1);
        $this->actingAs($user, 'api')
            ->patchJson($this->teamsUrl($org, $odd), [
                'home_team_id' => $odd->home_team_id,
                'away_team_id' => $spare,
            ])
            ->assertOk();

        // A scoreline recorded against a team that is no longer in the fixture
        // would still hand winnerOf() a winner, so it has to go with the slot.
        $semi = $this->slot($event, 2, 0);
        $this->assertNull($semi->away_team_id);
        $this->assertNull($semi->home_score);
        $this->assertNull($semi->away_score);
        $this->assertSame('scheduled', $semi->status);
        $this->assertNull($semi->confirmed_at);

        // And the phantom must not still be standing in the final.
        $this->assertNull($this->slot($event, 3, 0)->home_team_id);
    }

    public function test_editing_a_played_match_is_rejected(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);
        $spare = $this->spareTeam($event);

        $tie = $this->slot($event, 1, 0);
        $this->playAndConfirm($user, $org, $tie);
        $original = $tie->fresh()->home_team_id;

        $this->actingAs($user, 'api')
            ->patchJson($this->teamsUrl($org, $tie), [
                'home_team_id' => $spare,
                'away_team_id' => $tie->away_team_id,
            ])
            ->assertStatus(422);

        $this->assertSame($original, $tie->fresh()->home_team_id);
    }

    public function test_editing_a_bye_is_allowed_and_reseats_the_walkover(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        // 6 teams in a bracket of 8 → the first two ties are byes.
        $event = $this->knockoutEvent($org, teams: 6);
        $this->generate($user, $org, $event);
        $spare = $this->spareTeam($event);

        $bye = $this->slot($event, 1, 0);
        $this->assertNull($bye->away_team_id);
        $this->assertSame('finished', $bye->status);

        $walkedOver = $bye->home_team_id;
        $this->assertSame($walkedOver, $this->slot($event, 2, 0)->home_team_id);

        // The guard is keyed on the scoreline, not on status/confirmed_at —
        // otherwise a bye, which is written finished and confirmed, could never
        // be corrected.
        $this->actingAs($user, 'api')
            ->patchJson($this->teamsUrl($org, $bye), [
                'home_team_id' => $spare,
                'away_team_id' => null,
            ])
            ->assertOk();

        $bye->refresh();
        $this->assertSame($spare, $bye->home_team_id);
        $this->assertSame('finished', $bye->status);
        $this->assertNotNull($bye->confirmed_at);

        // The new occupant walks over in the old one's place.
        $this->assertSame($spare, $this->slot($event, 2, 0)->home_team_id);
    }

    public function test_filling_a_byes_empty_side_makes_it_a_real_tie(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, teams: 6);
        $this->generate($user, $org, $event);
        $spare = $this->spareTeam($event);

        $bye = $this->slot($event, 1, 0);
        $walkedOver = $bye->home_team_id;

        $this->actingAs($user, 'api')
            ->patchJson($this->teamsUrl($org, $bye), [
                'home_team_id' => $walkedOver,
                'away_team_id' => $spare,
            ])
            ->assertOk();

        $bye->refresh();
        $this->assertSame('scheduled', $bye->status);
        $this->assertNull($bye->confirmed_at);
        // The walkover is undone: nobody is through until this is played.
        $this->assertNull($this->slot($event, 2, 0)->home_team_id);
    }

    public function test_editing_a_later_round_is_rejected(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);
        $spare = $this->spareTeam($event);

        $this->actingAs($user, 'api')
            ->patchJson($this->teamsUrl($org, $this->slot($event, 2, 0)), [
                'home_team_id' => $spare,
                'away_team_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.feature', 'bracket_edit');
    }

    public function test_picking_a_team_from_another_slot_swaps_the_two(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);

        $tie = $this->slot($event, 1, 0);
        $donor = $this->slot($event, 1, 1);
        $mine = $tie->home_team_id;
        $theirs = $donor->home_team_id;

        // In a full bracket every eligible team is already placed, so a plain
        // replacement has nothing to offer — moving two seeds past each other
        // is the whole point.
        $this->actingAs($user, 'api')
            ->patchJson($this->teamsUrl($org, $tie), [
                'home_team_id' => $theirs,
                'away_team_id' => $tie->away_team_id,
            ])
            ->assertOk();

        $this->assertSame($theirs, $tie->fresh()->home_team_id);
        $this->assertSame($mine, $donor->fresh()->home_team_id, 'the displaced team takes the vacated slot');
    }

    public function test_a_swap_is_refused_when_the_other_slot_has_been_played(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);

        $donor = $this->slot($event, 1, 1);
        $this->playAndConfirm($user, $org, $donor);
        $theirs = $donor->fresh()->home_team_id;

        $tie = $this->slot($event, 1, 0);
        $mine = $tie->home_team_id;

        // Swapping would rewrite a fixture that has already been played.
        $this->actingAs($user, 'api')
            ->patchJson($this->teamsUrl($org, $tie), [
                'home_team_id' => $theirs,
                'away_team_id' => $tie->away_team_id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('home_team_id');

        $this->assertSame($mine, $tie->fresh()->home_team_id);
        $this->assertSame($theirs, $donor->fresh()->home_team_id);
    }

    public function test_swapping_the_two_sides_of_one_tie_touches_nothing_else(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);

        $tie = $this->slot($event, 1, 0);
        $other = $this->slot($event, 1, 1);
        $before = [$other->home_team_id, $other->away_team_id];

        // Both teams are already in this tie, so no donor is involved.
        $this->actingAs($user, 'api')
            ->patchJson($this->teamsUrl($org, $tie), [
                'home_team_id' => $tie->away_team_id,
                'away_team_id' => $tie->home_team_id,
            ])
            ->assertOk();

        $swapped = $tie->fresh();
        $this->assertSame($tie->away_team_id, $swapped->home_team_id);
        $this->assertSame($tie->home_team_id, $swapped->away_team_id);

        $other->refresh();
        $this->assertSame($before, [$other->home_team_id, $other->away_team_id]);
    }

    public function test_editing_a_group_stage_match_is_rejected(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

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
            'bracket_config' => ['groups' => 2, 'teams_per_group' => 4, 'qualification' => ['top_per_group' => 2]],
        ]);
        foreach (range(1, 8) as $i) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => 'Team '.$i,
                'status' => 'approved',
                'contact_name' => 'PIC',
                'contact_phone' => '0800',
            ]);
        }
        $event->load('categories');
        $this->generate($user, $org, $event);

        $group = $event->matches()->where('stage', 'group')->firstOrFail();

        $this->actingAs($user, 'api')
            ->patchJson($this->teamsUrl($org, $group), [
                'home_team_id' => $group->home_team_id,
                'away_team_id' => $group->away_team_id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.feature', 'bracket_edit');
    }

    public function test_editing_a_match_from_another_org_returns_404(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->knockoutEvent($org);
        $this->generate($owner, $org, $event);
        $spare = $this->spareTeam($event);

        $intruder = User::factory()->create();
        $otherOrg = $this->org($intruder);

        $tie = $this->slot($event, 1, 0);
        $original = $tie->home_team_id;

        $this->actingAs($intruder, 'api')
            ->patchJson("/api/v1/organizations/{$otherOrg->id}/matches/{$tie->id}/teams", [
                'home_team_id' => $spare,
                'away_team_id' => $tie->away_team_id,
            ])
            ->assertNotFound();

        $this->assertSame($original, $tie->fresh()->home_team_id);
    }

    public function test_manual_extra_fixture_does_not_block_a_single_elim_slot_edit(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);
        $spare = $this->spareTeam($event);
        $catId = $event->categories->first()->id;

        // A friendly outside the bracket, sharing (stage null, round 1) with it.
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$catId}/matches", [
                'home_team_id' => $spare,
                'away_team_id' => $this->slot($event, 1, 3)->home_team_id,
            ])
            ->assertCreated();

        // The swap must not treat that fixture as a slot to take a team from.
        $tie = $this->slot($event, 1, 0);
        $this->actingAs($user, 'api')
            ->patchJson($this->teamsUrl($org, $tie), [
                'home_team_id' => $spare,
                'away_team_id' => $tie->away_team_id,
            ])
            ->assertOk();

        $this->assertSame($spare, $tie->fresh()->home_team_id);

        // Nor is the friendly itself a slot anyone can re-seat.
        $friendly = $event->matches()->whereNull('stage')->where('order', 4)->firstOrFail();
        $this->actingAs($user, 'api')
            ->patchJson($this->teamsUrl($org, $friendly), [
                'home_team_id' => $friendly->home_team_id,
                'away_team_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.feature', 'bracket_edit');
    }
}
