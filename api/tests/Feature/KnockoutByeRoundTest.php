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
 * Where a knockout draw takes its byes.
 *
 * A field that is not a power of two has to sit somebody out, and there are two
 * shapes an organizer might have printed on their wall chart:
 *
 * - `first` pads the draw up to the next power of two and seats every bye in
 *   the opening round. 36 teams become a bracket of 64: eight play on day one
 *   and twenty-eight walk straight into round two.
 * - `late` pairs as many as it can in every round and byes whoever is left
 *   over. All 36 play round one — 18 ties — and the byes land where the numbers
 *   first stop halving: 18 → 9 → 5 → 3 → 2 → 1.
 *
 * Everything here is written as a *comparison* between the two, because almost
 * every single-shape assertion passes on a build that only ever draws one of
 * them. The deeper claim under test is that the ragged tree still walks the
 * positional edges advanceWinner() has always walked — a bye is the last slot
 * of its round, so the ties below it still pair (2k, 2k+1) into slot k.
 */
class KnockoutByeRoundTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    /** A knockout category of $teams entrants, drawn with $byeRound. */
    private function knockoutEvent(Organization $org, int $teams, ?string $byeRound = null): Event
    {
        $event = $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Panra Cup',
            'slug' => 'panra-'.uniqid(),
            'sport_type' => 'futsal',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $category = $event->categories()->create([
            'name' => 'SMA/SMK/MA',
            'slug' => 'sma',
            'participant_type' => 'team',
            'tournament_format' => 'knockout_single',
            'bracket_config' => $byeRound === null ? null : ['bye_round' => $byeRound],
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        foreach (range(1, $teams) as $i) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => 'Tim '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
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

    /** Ties per round, round 1 first. */
    private function roundSizes(Event $event): array
    {
        return $event->matches()
            ->whereNull('bracket')
            ->get()
            ->groupBy('round')
            ->sortKeys()
            ->map->count()
            ->values()
            ->all();
    }

    /**
     * Teams that actually play on day one — a bye seats a team too, which is
     * why this counts the ones with an opponent rather than the ones seated.
     */
    private function playersInRoundOne(Event $event): int
    {
        return $event->matches()->where('round', 1)->get()
            ->filter(fn (GameMatch $m) => $m->home_team_id && $m->away_team_id)
            ->count() * 2;
    }

    private function winMatch(User $user, Organization $org, GameMatch $match): void
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

    /**
     * The headline comparison: one field, two draws, two shapes. Asserting
     * either column on its own passes on a build that can only draw that one.
     */
    public function test_the_two_bye_placements_draw_different_shapes(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        $padded = $this->knockoutEvent($org, 36, 'first');
        $ragged = $this->knockoutEvent($org, 36, 'late');

        $this->generate($user, $org, $padded);
        $this->generate($user, $org, $ragged);

        $this->assertSame([32, 16, 8, 4, 2, 1], $this->roundSizes($padded));
        $this->assertSame([18, 9, 5, 3, 2, 1], $this->roundSizes($ragged));

        // The point of `late` for an organizer: nobody sits out day one.
        // 64 - 36 = 28 byes, so only four of the 32 opening slots are real ties.
        $this->assertSame(8, $this->playersInRoundOne($padded));
        $this->assertSame(36, $this->playersInRoundOne($ragged));
    }

    /** No config at all must keep drawing exactly what it drew before. */
    public function test_default_is_the_padded_shape(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, 6, null);

        $this->generate($user, $org, $event);

        $this->assertSame([4, 2, 1], $this->roundSizes($event));
    }

    /**
     * A ragged round-one bye is the *last* slot of its round, which is the
     * whole reason the positional edges still work.
     */
    public function test_a_ragged_first_round_byes_the_last_slot(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, 5, 'late');

        $this->generate($user, $org, $event);

        // 5 entrants: 3 ties, two real and one bye at the back.
        $this->assertSame([3, 2, 1], $this->roundSizes($event));

        $slots = $event->matches()->where('round', 1)->orderBy('order')->get();
        $this->assertNotNull($slots[0]->away_team_id);
        $this->assertNotNull($slots[1]->away_team_id);
        $this->assertNull($slots[2]->away_team_id);
        $this->assertSame('finished', $slots[2]->status);
        $this->assertNotNull($slots[2]->confirmed_at);
    }

    /**
     * A bye in a *later* round cannot be written by generation — nobody is in
     * that slot yet. It has to appear as the slot fills and its sibling feeder
     * turns out never to have existed.
     *
     * Six teams, `late`: round 1 has three ties, round 2 has two. Slot 1 of
     * round 2 is fed by tie 2 alone, so confirming tie 2 must walk its winner
     * straight through to the final rather than leaving it waiting forever on a
     * tie 3 that does not exist.
     */
    public function test_a_lone_feeder_walks_over_into_the_next_round(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, 6, 'late');

        $this->generate($user, $org, $event);
        $this->assertSame([3, 2, 1], $this->roundSizes($event));

        $feeder = $event->matches()->where('round', 1)->where('order', 2)->firstOrFail();
        $this->winMatch($user, $org, $feeder);

        $slot = $event->matches()->where('round', 2)->where('order', 1)->firstOrFail();

        // Seated, and settled: the walkover is written the same way generation
        // writes a first-round bye.
        $this->assertSame($feeder->home_team_id, $slot->fresh()->home_team_id);
        $this->assertNull($slot->fresh()->away_team_id);
        $this->assertSame('finished', $slot->fresh()->status);

        // And it kept going: the final holds that team already.
        $final = $event->matches()->where('round', 3)->where('order', 0)->firstOrFail();
        $this->assertSame($feeder->home_team_id, $final->away_team_id);
    }

    /**
     * The mirror of the test above, and the one that separates "a sibling does
     * not exist" from "a sibling has not been played". Round-2 slot 0 of the
     * same draw is fed by two real ties; confirming one of them must leave the
     * slot waiting, not walk its occupant over.
     */
    public function test_a_half_filled_slot_with_a_real_sibling_is_not_a_walkover(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, 6, 'late');

        $this->generate($user, $org, $event);

        $feeder = $event->matches()->where('round', 1)->where('order', 0)->firstOrFail();
        $this->winMatch($user, $org, $feeder);

        $slot = $event->matches()->where('round', 2)->where('order', 0)->firstOrFail();

        $this->assertSame($feeder->home_team_id, $slot->home_team_id);
        $this->assertNull($slot->away_team_id);
        $this->assertSame('scheduled', $slot->status, 'A tie whose sibling is merely unplayed must not walk over.');
        $this->assertNull($slot->confirmed_at);
    }

    /**
     * How wide the opening round is cannot be read back off the tree — a round
     * of N ties was fed by either 2N or 2N-1 — so it is recorded. Without it a
     * hand-added friendly (which shares `stage null, round 1` and whose order
     * keeps counting past the bracket) would be mistaken for a slot.
     */
    public function test_it_records_how_wide_the_opening_round_is(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        $padded = $this->knockoutEvent($org, 36, 'first');
        $ragged = $this->knockoutEvent($org, 36, 'late');

        $this->generate($user, $org, $padded);
        $this->generate($user, $org, $ragged);

        $this->assertSame(32, $padded->categories->first()->fresh()->bracket_slots);
        $this->assertSame(18, $ragged->categories->first()->fresh()->bracket_slots);
    }
}
