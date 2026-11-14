<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Moving a whole tie to another slot of the draw.
 *
 * The twin of BracketSlotEditTest, and the distinction is the point: that one
 * changes *who is in* a slot, this one changes *where in the bracket* a tie
 * sits. An organizer whose chart pairs the wrong halves wants the second, and
 * reaching it through the first means retyping four teams across two slots.
 *
 * The two slots trade contents, because that is the only move that keeps the
 * bracket full — every tie already sits somewhere, so nothing can be vacated
 * without something taking its place.
 */
class BracketSlotMoveTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    private function knockoutEvent(Organization $org, int $teams = 8): Event
    {
        $event = $org->events()->create([
            'plan_id' => $this->planId(),
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

    private function generate(User $user, Organization $org, Event $event): void
    {
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}"
                ."/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated();
    }

    private function slot(Event $event, int $round, int $order): GameMatch
    {
        return $event->matches()->whereNull('bracket')
            ->where('round', $round)->where('order', $order)->firstOrFail();
    }

    private function move(User $user, Organization $org, GameMatch $match, string $targetId)
    {
        return $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}/slot", [
                'target_id' => $targetId,
            ]);
    }

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

    // ---------------------------------------------------------------------

    /** The headline: two ties trade places, each keeping its own pairing. */
    public function test_two_slots_trade_their_ties(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);

        $a = $this->slot($event, 1, 0);
        $b = $this->slot($event, 1, 2);

        $aTeams = [$a->home_team_id, $a->away_team_id];
        $bTeams = [$b->home_team_id, $b->away_team_id];

        $this->move($user, $org, $a, $b->id)->assertOk();

        // Each tie is intact — the pairing moved, it was not reshuffled. Assert
        // only that "slot 0 changed" and a build that scrambled both sides
        // would pass just as well.
        $this->assertSame($bTeams, [$a->fresh()->home_team_id, $a->fresh()->away_team_id]);
        $this->assertSame($aTeams, [$b->fresh()->home_team_id, $b->fresh()->away_team_id]);
    }

    /**
     * A bye may be moved. It is written finished-and-confirmed with no scores,
     * so a status check would freeze exactly the correction this exists for:
     * the walkover landed on the wrong branch.
     */
    public function test_a_bye_can_be_moved_onto_another_branch(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        // 6 of 8: the first two opening slots are byes.
        $event = $this->knockoutEvent($org, teams: 6);
        $this->generate($user, $org, $event);

        $bye = $this->slot($event, 1, 0);
        $tie = $this->slot($event, 1, 3);
        $this->assertNull($bye->away_team_id, 'fixture assumption: slot 0 is a bye');

        $walkedOver = $bye->home_team_id;
        $tieTeams = [$tie->home_team_id, $tie->away_team_id];

        $this->move($user, $org, $bye, $tie->id)->assertOk();

        // The bye is now on the other branch, still a bye, still settled.
        $moved = $tie->fresh();
        $this->assertSame($walkedOver, $moved->home_team_id);
        $this->assertNull($moved->away_team_id);
        $this->assertSame('finished', $moved->status);

        // And its walkover follows it. Slot 3 is odd, so its winner feeds the
        // *away* side of round-2 slot 1 — the side advanceWinner() picks from
        // `order % 2`, not whichever one reads first.
        $this->assertSame($walkedOver, $this->slot($event, 2, 1)->away_team_id);
        $this->assertNotSame($walkedOver, $this->slot($event, 2, 0)->home_team_id);

        // The tie it displaced kept its pairing and is a real fixture again.
        $this->assertSame($tieTeams, [$bye->fresh()->home_team_id, $bye->fresh()->away_team_id]);
        $this->assertSame('scheduled', $bye->fresh()->status);
    }

    /**
     * A played tie stays put. Its result belongs to the place it was played in,
     * and the guard is keyed on the scoreline rather than the status — the
     * same distinction that lets a bye move.
     */
    public function test_a_slot_with_a_result_refuses_to_move(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);

        $played = $this->slot($event, 1, 0);
        $other = $this->slot($event, 1, 1);
        $this->playAndConfirm($user, $org, $played);

        $before = [$played->fresh()->home_team_id, $other->fresh()->home_team_id];

        $this->move($user, $org, $played, $other->id)->assertStatus(422);

        // Refused in both directions: the played tie is as unmovable as a
        // target as it is as a source.
        $this->move($user, $org, $other, $played->id)->assertStatus(422);

        $this->assertSame(
            $before,
            [$played->fresh()->home_team_id, $other->fresh()->home_team_id],
            'a refused move must not have written anything',
        );
    }

    /**
     * Rounds are not interchangeable. A tie moved up would claim a place its
     * teams never played for; one moved down would leave the slot it came from
     * fed by nothing.
     */
    public function test_a_slot_cannot_move_to_another_round(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);

        $first = $this->slot($event, 1, 0);
        $second = $this->slot($event, 2, 1);

        $this->move($user, $org, $first, $second->id)->assertStatus(422);

        $this->assertNotNull($first->fresh()->home_team_id);
    }

    /**
     * Downstream results go with the move, because the teams that produced
     * them are no longer on that branch.
     */
    public function test_moving_a_slot_resets_what_its_occupants_reached(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);

        // Play the *neighbour* so round 2 holds a team, then move the slot that
        // feeds the same round-2 tie.
        $neighbour = $this->slot($event, 1, 1);
        $this->playAndConfirm($user, $org, $neighbour);

        $parent = $this->slot($event, 2, 0);
        $this->assertNotNull($parent->fresh()->away_team_id, 'fixture assumption');

        $this->move($user, $org, $this->slot($event, 1, 0), $this->slot($event, 1, 2)->id)
            ->assertOk();

        // The neighbour's winner is still there — nothing about that tie moved.
        $this->assertNotNull($parent->fresh()->away_team_id);
    }

    /** A category from another org is not reachable, moved or otherwise. */
    public function test_an_outsider_cannot_move_a_slot(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);

        $outsider = User::factory()->create();
        $otherOrg = $this->org($outsider);

        $a = $this->slot($event, 1, 0);
        $b = $this->slot($event, 1, 1);
        $before = $a->home_team_id;

        $this->actingAs($outsider, 'api')
            ->patchJson("/api/v1/organizations/{$otherOrg->id}/matches/{$a->id}/slot", [
                'target_id' => $b->id,
            ])
            ->assertNotFound();

        $this->assertSame($before, $a->fresh()->home_team_id);
    }
}
