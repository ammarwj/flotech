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
 * Which side of a tie hosts.
 *
 * The third question about a slot, after BracketSlotEditTest ("who is in it")
 * and BracketSlotMoveTest ("which branch does it sit on"). A pairing can be
 * right in both and still name the wrong host.
 *
 * The whole file turns on one distinction: round one *stores* its sides, every
 * later round *derives* them from feeder parity. So the swap has to reach a
 * different place in each, and a build that writes the columns in both passes
 * every assertion that only reads them back — until a feeder is confirmed and
 * advanceWinner() puts the winner back on the side the parity names.
 */
class BracketSideSwapTest extends TestCase
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

    protected function swapSides(User $user, Organization $org, GameMatch $match)
    {
        return $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}/sides");
    }

    private function playAndConfirm(User $user, Organization $org, GameMatch $match, int $home = 2, int $away = 1): void
    {
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}", [
                'status' => 'finished', 'home_score' => $home, 'away_score' => $away,
            ])
            ->assertOk();

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}/confirm", ['confirmed' => true])
            ->assertOk();
    }

    // ---------------------------------------------------------------------

    /** Round one stores its sides, so the swap is the two columns trading. */
    public function test_round_one_swaps_its_own_columns(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);

        $slot = $this->slot($event, 1, 0);
        $home = $slot->home_team_id;
        $away = $slot->away_team_id;

        $this->swapSides($user, $org, $slot)->assertOk();

        $after = $slot->fresh();
        $this->assertSame($away, $after->home_team_id);
        $this->assertSame($home, $after->away_team_id);
    }

    /**
     * The headline, and the one assertion a wrong build cannot fake.
     *
     * A later round reads its sides off feeder parity, so the swap trades the
     * feeders. Writing the parent's columns instead reads back correctly right
     * up until a feeder is confirmed — at which point advanceWinner() puts the
     * winner back on the side `order % 2` names and the swap is silently
     * undone. Confirming afterwards is therefore the test; comparing the
     * columns alone is not.
     */
    public function test_a_later_round_swaps_its_feeders_and_the_swap_survives_a_result(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);

        $parent = $this->slot($event, 2, 0);
        $feedHome = $this->slot($event, 1, 0);
        $feedAway = $this->slot($event, 1, 1);

        $homeFeederTeams = [$feedHome->home_team_id, $feedHome->away_team_id];
        $awayFeederTeams = [$feedAway->home_team_id, $feedAway->away_team_id];

        $this->swapSides($user, $org, $parent)->assertOk();

        // The feeders traded contents — each pairing intact, on the other
        // branch. This is where the change actually lives.
        $this->assertSame(
            $awayFeederTeams,
            [$feedHome->fresh()->home_team_id, $feedHome->fresh()->away_team_id],
        );
        $this->assertSame(
            $homeFeederTeams,
            [$feedAway->fresh()->home_team_id, $feedAway->fresh()->away_team_id],
        );

        // Now play what is *now* the even-ordered feeder. Its winner must land
        // on the parent's home side, which is the pairing that used to be away.
        $this->playAndConfirm($user, $org, $feedHome->fresh());

        $this->assertSame(
            $awayFeederTeams[0],
            $parent->fresh()->home_team_id,
            'the swap must survive a feeder being confirmed',
        );
    }

    /**
     * A bye has nothing to swap. Moving its team to the away column is the one
     * shape generation does not read as a walkover, so the tie would quietly
     * stop advancing anybody.
     */
    public function test_a_bye_cannot_swap_sides(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        // 6 of 8: the first two opening slots are byes.
        $event = $this->knockoutEvent($org, teams: 6);
        $this->generate($user, $org, $event);

        $bye = $this->slot($event, 1, 0);
        $this->assertNull($bye->away_team_id, 'fixture assumption: slot 0 is a bye');

        $this->swapSides($user, $org, $bye)->assertStatus(422);

        $after = $bye->fresh();
        $this->assertNotNull($after->home_team_id);
        $this->assertNull($after->away_team_id);
    }

    /**
     * The regression this file was extended for, and it cost real data.
     *
     * A hand-typed team sat on the side a bye feeds — updateTeams() permits
     * exactly that, because a bye-fed slot was reached by nobody playing. The
     * swap rewrote the bye, seatSlot() re-fired its walkover, and
     * advanceWinner() put the walkover's team on that side, over the one that
     * was typed. No error, no trace: the displaced team ended up in no slot at
     * all. That is how SMP YP-BDN was lost from the KASUDIN CUP draw.
     *
     * Compared against the swap below, which the same guard must still allow —
     * assert the refusal alone and a build that refuses every bye passes.
     */
    public function test_a_swap_that_would_strand_a_hand_typed_team_is_refused(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        // 6 of 8: opening slots 0 and 1 are byes, so round-2 slot 0 is fed by
        // two walkovers — and is therefore editable.
        $event = $this->knockoutEvent($org, teams: 6);
        $this->generate($user, $org, $event);

        $parent = $this->slot($event, 2, 0);
        $byeHome = $this->slot($event, 1, 0);
        $byeAway = $this->slot($event, 1, 1);
        $this->assertNull($byeHome->away_team_id, 'fixture assumption: slot 0 is a bye');
        $this->assertNull($byeAway->away_team_id, 'fixture assumption: slot 1 is a bye');

        // Type somebody over the away side the second bye walked into. This is
        // the state the real draw was in.
        $outsider = $event->teams()
            ->whereNotIn('id', [$byeHome->home_team_id, $byeAway->home_team_id])
            ->firstOrFail();

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$parent->id}/teams", [
                'home_team_id' => $parent->home_team_id,
                'away_team_id' => $outsider->id,
            ])
            ->assertOk();

        $this->assertSame($outsider->id, $parent->fresh()->away_team_id, 'fixture assumption');

        $this->swapSides($user, $org, $parent)->assertStatus(422);

        // The whole point: the hand-typed team is still there, and still in
        // the bracket. Asserting only the 422 would pass a build that refused
        // after already writing.
        $this->assertSame($outsider->id, $parent->fresh()->away_team_id);
    }

    /**
     * The other half of the guard: a bye whose side is empty displaces
     * nobody, so the swap goes through.
     *
     * Without this, "refuse every swap whose feeder is a bye" passes the test
     * above — and that would block the correction byes most need.
     */
    public function test_a_swap_is_allowed_when_the_bye_displaces_nobody(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        // 7 of 8: only opening slot 0 is a bye, and round-2 slot 0's away side
        // waits on a real tie, so nothing was typed over anything.
        $event = $this->knockoutEvent($org, teams: 7);
        $this->generate($user, $org, $event);

        $parent = $this->slot($event, 2, 0);
        $bye = $this->slot($event, 1, 0);
        $tie = $this->slot($event, 1, 1);
        $this->assertNull($bye->away_team_id, 'fixture assumption: slot 0 is a bye');
        $this->assertNull($parent->away_team_id, 'fixture assumption: nothing typed in');

        $walkedOver = $bye->home_team_id;
        $tieTeams = [$tie->home_team_id, $tie->away_team_id];

        $this->swapSides($user, $org, $parent)->assertOk();

        // The feeders traded, and the walkover now feeds the away side.
        $this->assertSame($tieTeams, [$bye->fresh()->home_team_id, $bye->fresh()->away_team_id]);
        $this->assertSame($walkedOver, $tie->fresh()->home_team_id);
        $this->assertSame($walkedOver, $parent->fresh()->away_team_id);
    }

    /**
     * A played feeder holds its result where it was played — the same guard
     * moveSlot() applies, reached through the parent.
     *
     * Compared against the round-one refusal in the test above so that a build
     * which simply refuses everything cannot pass: the first case here is a
     * refusal, and the test above is an acceptance.
     */
    public function test_a_played_feeder_blocks_the_swap(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);

        $parent = $this->slot($event, 2, 0);
        $feedHome = $this->slot($event, 1, 0);
        $feedAway = $this->slot($event, 1, 1);

        $this->playAndConfirm($user, $org, $feedHome);

        $before = [
            [$feedHome->fresh()->home_team_id, $feedHome->fresh()->away_team_id],
            [$feedAway->fresh()->home_team_id, $feedAway->fresh()->away_team_id],
        ];

        $this->swapSides($user, $org, $parent)->assertStatus(422);

        $this->assertSame(
            $before,
            [
                [$feedHome->fresh()->home_team_id, $feedHome->fresh()->away_team_id],
                [$feedAway->fresh()->home_team_id, $feedAway->fresh()->away_team_id],
            ],
            'a refused swap must not have written anything',
        );
    }

    /** A league fixture has no bracket sides to trade. */
    public function test_a_non_bracket_fixture_refuses(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        $event = $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Liga',
            'slug' => 'liga-'.uniqid(),
            'sport_type' => 'mini_soccer',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $category = $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum',
            'tournament_format' => 'league', 'registration_fee' => 0, 'sort_order' => 0,
        ]);

        foreach (range(1, 4) as $i) {
            $event->teams()->create([
                'category_id' => $category->id, 'name' => 'Team '.$i, 'status' => 'approved',
                'contact_name' => 'PIC', 'contact_phone' => '0800',
            ]);
        }

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/schedule")
            ->assertCreated();

        $fixture = $event->matches()->firstOrFail();

        $this->swapSides($user, $org, $fixture)->assertStatus(422);
    }

    /** A category from another org is not reachable. */
    public function test_an_outsider_cannot_swap_sides(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);

        $outsider = User::factory()->create();
        $otherOrg = $this->org($outsider);

        $slot = $this->slot($event, 1, 0);
        $before = $slot->home_team_id;

        $this->actingAs($outsider, 'api')
            ->patchJson("/api/v1/organizations/{$otherOrg->id}/matches/{$slot->id}/sides")
            ->assertNotFound();

        $this->assertSame($before, $slot->fresh()->home_team_id);
    }
}
