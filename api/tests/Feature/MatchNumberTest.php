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
 * Naming a fixture whose teams do not exist yet.
 *
 * A bracket slot waiting on earlier results prints "TBD vs TBD" on every
 * screen, so an organizer writing the match-day programme cannot refer to it,
 * tell two of them apart, or say which tie feeds it. The answer is a running
 * number per category plus a caption per empty side — "Pemenang P5" — both
 * derived on the server so the five surfaces that print them cannot disagree.
 */
class MatchNumberTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

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

    private function categoryUrl(Organization $org, Event $event, string $path = ''): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$event->id}"
            ."/categories/{$event->categories->first()->id}".$path;
    }

    private function generate(User $user, Organization $org, Event $event): void
    {
        $this->actingAs($user, 'api')
            ->postJson($this->categoryUrl($org, $event, '/schedule'))
            ->assertCreated();
    }

    /** The fixture list as the schedule page receives it, keyed by match id. */
    private function payload(User $user, Organization $org, Event $event): array
    {
        $rows = $this->actingAs($user, 'api')
            ->getJson($this->categoryUrl($org, $event, '/matches'))
            ->assertOk()
            ->json('data');

        return collect($rows)->keyBy('id')->all();
    }

    private function slot(Event $event, int $round, int $order): GameMatch
    {
        return $event->matches()->whereNull('bracket')
            ->where('round', $round)->where('order', $order)->firstOrFail();
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

    /** Every fixture gets a number, in the order every screen lists them in. */
    public function test_it_numbers_fixtures_in_list_order(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, 8);
        $this->generate($user, $org, $event);

        $rows = $this->payload($user, $org, $event);

        $this->assertSame(1, $rows[$this->slot($event, 1, 0)->id]['match_no']);
        $this->assertSame(4, $rows[$this->slot($event, 1, 3)->id]['match_no']);
        $this->assertSame(5, $rows[$this->slot($event, 2, 0)->id]['match_no']);
        $this->assertSame(7, $rows[$this->slot($event, 3, 0)->id]['match_no']);
    }

    /**
     * The headline comparison: a slot with one feeder already decided must name
     * the team on that side and the *feeder* on the other. Asserting either
     * alone passes on a build that captions everything, or nothing.
     */
    public function test_an_empty_side_names_the_tie_that_feeds_it(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, 8);
        $this->generate($user, $org, $event);

        $semi = $this->slot($event, 2, 0);

        // Nothing played: both sides are still waiting on their openers.
        $before = $this->payload($user, $org, $event)[$semi->id];
        $this->assertSame('Pemenang P1', $before['home_source']);
        $this->assertSame('Pemenang P2', $before['away_source']);

        $this->winMatch($user, $org, $this->slot($event, 1, 0));

        $after = $this->payload($user, $org, $event)[$semi->id];

        // The filled side says nothing — its team's own name is the answer, and
        // captioning it would hide that answer behind the question.
        $this->assertNull($after['home_source'], 'a seated side was still captioned with its feeder');
        $this->assertNotNull($after['home_team']);
        // The other side is unchanged: still waiting on tie 2.
        $this->assertSame('Pemenang P2', $after['away_source']);
    }

    /**
     * A bye is not a source. Nobody played it, so "Pemenang P3" would send a
     * reader to look up a fixture that will never have a result — and the team
     * that walked over is seated in the slot already.
     */
    public function test_a_bye_never_captions_the_slot_it_feeds(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        // 6 of 8: the first two opening ties are byes.
        $event = $this->knockoutEvent($org, 6);
        $this->generate($user, $org, $event);

        $bye = $this->slot($event, 1, 0);
        $this->assertNull($bye->away_team_id);

        $semi = $this->slot($event, 2, 0);
        $rows = $this->payload($user, $org, $event);

        // Both its feeders are byes, so both sides are seated and neither is
        // captioned.
        $this->assertNull($rows[$semi->id]['home_source']);
        $this->assertNull($rows[$semi->id]['away_source']);

        // Compared against a slot fed by two real ties, which is captioned.
        $other = $this->slot($event, 2, 1);
        $this->assertSame('Pemenang P3', $rows[$other->id]['home_source']);
        $this->assertSame('Pemenang P4', $rows[$other->id]['away_source']);
    }

    /**
     * The ragged shape is the one this was built for: 36 teams, 18 openers, and
     * a round-two slot fed by two of them.
     */
    public function test_it_captions_a_ragged_draw(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, 36, 'late');
        $this->generate($user, $org, $event);

        $rows = $this->payload($user, $org, $event);

        // Round 2 starts right after the 18 openers.
        $this->assertSame(19, $rows[$this->slot($event, 2, 0)->id]['match_no']);
        $this->assertSame('Pemenang P1', $rows[$this->slot($event, 2, 0)->id]['home_source']);
        $this->assertSame('Pemenang P2', $rows[$this->slot($event, 2, 0)->id]['away_source']);

        // 18 openers pair up exactly, so round 2 is full at nine — the odd slot
        // is one round further on (9 → 5), and *that* is the one with a single
        // feeder. Its empty side is not captioned, because nothing will ever
        // arrive there.
        $lonely = $this->slot($event, 3, 4);
        $this->assertSame('Pemenang P27', $rows[$lonely->id]['home_source']);
        // Not "TBD": nothing can ever arrive there, and promising an opponent
        // who will not come is the one thing the wall chart never does.
        $this->assertSame('Bye', $rows[$lonely->id]['away_source']);

        // Compared against its neighbour, which has two feeders and says so.
        $paired = $this->slot($event, 3, 0);
        $this->assertSame('Pemenang P19', $rows[$paired->id]['home_source']);
        $this->assertSame('Pemenang P20', $rows[$paired->id]['away_source']);
    }

    /**
     * The third-place tie takes the *losers* of the round below, so it must say
     * so — "Pemenang" there would name the two teams playing the final.
     */
    public function test_the_third_place_tie_names_the_losers(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, 4);
        $event->categories->first()->update(['bracket_config' => ['third_place' => true]]);
        $this->generate($user, $org, $event);

        $third = $event->matches()->where('bracket', 'third_place')->firstOrFail();
        $rows = $this->payload($user, $org, $event);

        $this->assertSame('Kalah P1', $rows[$third->id]['home_source']);
        $this->assertSame('Kalah P2', $rows[$third->id]['away_source']);
    }

    /**
     * A league has no feeders at all, and a caption invented for one would be
     * pointing at a fixture that decides nothing.
     */
    public function test_a_league_fixture_is_numbered_but_never_captioned(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, 4);
        $event->categories->first()->update(['tournament_format' => 'league']);
        $this->generate($user, $org, $event);

        $rows = $this->payload($user, $org, $event);

        foreach ($rows as $row) {
            $this->assertNotNull($row['match_no']);
            $this->assertNull($row['home_source']);
            $this->assertNull($row['away_source']);
        }
    }

    /** The public schedule carries the same numbers the organizer's does. */
    public function test_the_public_schedule_carries_the_same_numbers(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, 8);
        $this->generate($user, $org, $event);

        $category = $event->categories->first();
        $mine = $this->payload($user, $org, $event);

        $theirs = collect(
            $this->getJson("/api/v1/public/events/{$org->slug}/{$event->slug}/categories/{$category->slug}/matches")
                ->assertOk()
                ->json('data')
        )->keyBy('id')->all();

        $semi = $this->slot($event, 2, 0)->id;

        $this->assertSame($mine[$semi]['match_no'], $theirs[$semi]['match_no']);
        $this->assertSame('Pemenang P1', $theirs[$semi]['home_source']);
    }
}
