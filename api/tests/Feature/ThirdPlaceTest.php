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
 * The tie between the two beaten semifinalists.
 *
 * It shares the final's round and is told apart by `bracket = 'third_place'`,
 * so most of what is asserted here is that it stays *out* of the winner
 * advancement it sits next to — and that a semifinal's loser is withdrawn again
 * whenever the semifinal stops saying they lost.
 */
class ThirdPlaceTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    private function event(Organization $org, bool $thirdPlace = true, int $teams = 4): Event
    {
        $event = $org->events()->create([
            'name' => 'Third Cup',
            'slug' => 'third-cup-'.uniqid(),
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
            'bracket_config' => ['third_place' => $thirdPlace],
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

    private function third(Event $event): ?GameMatch
    {
        return $event->matches()->where('bracket', 'third_place')->first();
    }

    private function semi(Event $event, int $order): GameMatch
    {
        return $event->matches()->where('round', 1)->where('order', $order)->firstOrFail();
    }

    private function final(Event $event): GameMatch
    {
        return $event->matches()->where('round', 2)->where('order', 0)->firstOrFail();
    }

    /** Score it so the home side wins, then sign it off (the owner auto-confirms). */
    private function play(User $user, Organization $org, GameMatch $match, int $home = 2, int $away = 1): void
    {
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}", [
                'status' => 'finished', 'home_score' => $home, 'away_score' => $away,
            ])
            ->assertOk();
    }

    public function test_no_third_place_tie_unless_the_category_asks_for_one(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org, thirdPlace: false);
        $this->generate($user, $org, $event);

        $this->assertNull($this->third($event));
        $this->assertSame(3, $event->matches()->count()); // 2 semis + final
    }

    public function test_it_is_created_beside_the_final(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $third = $this->third($event);
        $this->assertNotNull($third);
        $this->assertSame($this->final($event)->round, $third->round, 'played alongside the final');
        $this->assertSame(1, $third->order);
        $this->assertSame(4, $event->matches()->count());
    }

    public function test_a_bracket_with_no_semifinals_gets_none(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        // Two teams is a final and nothing else — there is no third place.
        $event = $this->event($org, thirdPlace: true, teams: 2);
        $this->generate($user, $org, $event);

        $this->assertNull($this->third($event));
    }

    public function test_both_beaten_semifinalists_land_in_it(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $semiA = $this->semi($event, 0);
        $semiB = $this->semi($event, 1);
        $loserA = $semiA->away_team_id;
        $loserB = $semiB->away_team_id;

        $this->play($user, $org, $semiA);
        $this->play($user, $org, $semiB);

        $third = $this->third($event);
        $this->assertSame($loserA, $third->home_team_id);
        $this->assertSame($loserB, $third->away_team_id);
    }

    public function test_the_final_still_gets_the_winners(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $semiA = $this->semi($event, 0);
        $semiB = $this->semi($event, 1);
        $winnerA = $semiA->home_team_id;
        $winnerB = $semiB->home_team_id;

        $this->play($user, $org, $semiA);
        $this->play($user, $org, $semiB);

        // The third-place tie shares the final's round; if it were ever mistaken
        // for a parent, a winner would land there instead of here.
        $final = $this->final($event);
        $this->assertSame($winnerA, $final->home_team_id);
        $this->assertSame($winnerB, $final->away_team_id);
    }

    public function test_flipping_a_semifinal_swaps_who_plays_for_third(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $semi = $this->semi($event, 0);
        $home = $semi->home_team_id;
        $away = $semi->away_team_id;

        $this->play($user, $org, $semi, home: 2, away: 1);
        $this->assertSame($away, $this->third($event)->home_team_id);

        // The other side won after all: the third-place slot must follow.
        $this->play($user, $org, $semi, home: 1, away: 2);
        $this->assertSame($home, $this->third($event)->home_team_id);
    }

    public function test_cancelling_a_semifinal_empties_its_third_place_slot(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $semiA = $this->semi($event, 0);
        $semiB = $this->semi($event, 1);
        $this->play($user, $org, $semiA);
        $this->play($user, $org, $semiB);

        $fromB = $this->third($event)->away_team_id;
        $this->assertNotNull($fromB);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$semiA->id}/status", ['status' => 'cancelled'])
            ->assertOk();

        $third = $this->third($event);
        $this->assertNull($third->home_team_id, 'the cancelled semifinal withdraws its loser');
        $this->assertSame($fromB, $third->away_team_id, 'the other semifinal is untouched');
    }

    public function test_the_third_place_tie_never_advances_anyone(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $this->play($user, $org, $this->semi($event, 0));
        $this->play($user, $org, $this->semi($event, 1));

        $final = $this->final($event);
        $before = [$final->home_team_id, $final->away_team_id];

        $this->play($user, $org, $this->third($event));

        $final->refresh();
        $this->assertSame($before, [$final->home_team_id, $final->away_team_id]);
    }
}
