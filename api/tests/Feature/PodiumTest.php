<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\PodiumService;
use App\Services\StandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Who finished first, second and third — the public "Juara" tab.
 *
 * The podium is derived, never stored, so most of what is asserted here is
 * that it stays shut until the result that decides it is final, and that it
 * stops at two places rather than guessing a third the bracket never played.
 */
class PodiumTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function event(Organization $org, string $format, int $teams, array $config = []): Event
    {
        $event = $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Cup',
            'slug' => 'cup-'.uniqid(),
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
            'bracket_config' => $config === [] ? null : $config,
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

    /**
     * Score a fixture, and leave it confirmed or not as asked.
     *
     * The owner signs off by the act of saving (see
     * MatchController::updateResult), so an unconfirmed result has to be
     * unconfirmed back explicitly — it cannot be had by skipping a step.
     */
    private function play(
        User $user,
        Organization $org,
        GameMatch $match,
        int $home = 2,
        int $away = 1,
        bool $confirm = true,
    ): void {
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}", [
                'status' => 'finished', 'home_score' => $home, 'away_score' => $away,
            ])
            ->assertOk();

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}/confirm", ['confirmed' => $confirm])
            ->assertOk();
    }

    /**
     * Play out the whole category, the alphabetically earlier team winning.
     *
     * Not "home always wins", which was the first thing tried: in a four-team
     * round robin that leaves three teams level on points with nothing in the
     * config able to separate them, every row comes back `needs_decider`, and
     * the podium correctly refuses to name anyone. Seeding by name gives a
     * strict 3-2-1-0 order, so the table under test has a real top three.
     *
     * Re-queried every pass rather than looped over one snapshot: a bracket
     * fills its next round only as the current one is decided, so a fixture
     * read up front has no teams yet and would be skipped forever.
     */
    private function playRemaining(User $user, Organization $org, Event $event): void
    {
        $names = $event->teams()->pluck('name', 'id')->all();

        for ($pass = 0; $pass < 12; $pass++) {
            $next = $event->matches()
                ->whereNull('home_score')
                ->whereNotNull('home_team_id')
                ->whereNotNull('away_team_id')
                ->orderBy('round')
                ->orderBy('order')
                ->get();

            if ($next->isEmpty()) {
                return;
            }

            foreach ($next as $m) {
                $homeWins = ($names[$m->home_team_id] ?? '') < ($names[$m->away_team_id] ?? '');

                $this->play($user, $org, $m, home: $homeWins ? 2 : 1, away: $homeWins ? 1 : 2);
            }
        }

        $this->fail('fixtures never stopped appearing — the bracket did not settle');
    }

    private function podium(Event $event): array
    {
        return app(PodiumService::class)->forCategory($event->categories->first()->fresh());
    }

    private function names(array $podium): array
    {
        return array_map(fn ($p) => $p['team']['name'], $podium);
    }

    /**
     * The whole point of deriving rather than storing: a final that has been
     * scored but not confirmed announces nobody.
     *
     * Asserted by COMPARING the two states of the same fixture. Asserting the
     * confirmed case alone passes just as well on a build that never checks
     * `confirmed_at` and crowns a champion the organizer is still reviewing.
     */
    public function test_a_final_is_not_a_podium_until_it_is_confirmed(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org, 'knockout_single', 4);
        $this->generate($user, $org, $event);

        foreach ($event->matches()->where('round', 1)->get() as $semi) {
            $this->play($user, $org, $semi);
        }

        $final = $event->matches()->where('round', 2)->where('order', 0)->firstOrFail();

        $this->play($user, $org, $final, confirm: false);
        $this->assertSame([], $this->podium($event), 'scored but unconfirmed must announce nobody');

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$final->id}/confirm", ['confirmed' => true])
            ->assertOk();

        $this->assertCount(2, $this->podium($event), 'the same fixture, now confirmed, must decide it');
    }

    /**
     * A category that never scheduled a third-place tie stops at two.
     *
     * Compared against the same draw WITH the tie, because that is the only
     * way to tell a build that correctly declines to guess from one that
     * silently drops third place everywhere.
     */
    public function test_third_place_is_absent_without_its_tie_and_present_with_it(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        $without = $this->event($org, 'knockout_single', 4);
        $this->generate($user, $org, $without);
        $this->playRemaining($user, $org, $without);

        $with = $this->event($org, 'knockout_single', 4, ['third_place' => true]);
        $this->generate($user, $org, $with);
        $this->playRemaining($user, $org, $with);

        $this->assertCount(2, $this->podium($without), 'no third-place tie means no third place');

        $third = $with->matches()->where('bracket', 'third_place')->first();
        $this->assertNotNull($third, 'bracket_config.third_place must schedule the tie');

        $podium = $this->podium($with);
        $this->assertCount(3, $podium);
        $this->assertSame([1, 2, 3], array_column($podium, 'place'));
        $this->assertSame(
            $third->fresh()->home_team_id,
            $podium[2]['team']['id'],
            'third place is the winner of its own tie, not a beaten semifinalist',
        );
    }

    /**
     * First and second are the two sides of the final, in that order.
     */
    public function test_the_final_decides_first_and_second(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org, 'knockout_single', 4);
        $this->generate($user, $org, $event);

        foreach ($event->matches()->where('round', 1)->get() as $semi) {
            $this->play($user, $org, $semi);
        }

        // Away wins this one, so the podium cannot be an artefact of always
        // reading home_team_id first.
        $final = $event->matches()->where('round', 2)->where('order', 0)->firstOrFail();
        $this->play($user, $org, $final, home: 1, away: 3);

        $final = $final->fresh();
        $podium = $this->podium($event);

        $this->assertSame($final->away_team_id, $podium[0]['team']['id'], 'winner is first');
        $this->assertSame($final->home_team_id, $podium[1]['team']['id'], 'loser is second');
    }

    /**
     * A league reads its table, and it waits for the last fixture.
     *
     * Both halves in one test: a build that skips the unplayed check passes the
     * second assertion alone, and a build that never reads standings passes the
     * first.
     */
    public function test_a_league_podium_waits_for_the_table_to_finish(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org, 'league', 4);
        $this->generate($user, $org, $event);

        $all = $event->matches()->orderBy('round')->orderBy('order')->get();
        $this->assertGreaterThan(1, $all->count());

        $this->play($user, $org, $all->first());
        $this->assertSame([], $this->podium($event), 'one result in is not a table');

        $this->playRemaining($user, $org, $event);

        $podium = $this->podium($event);
        $standings = app(StandingService::class)->compute($event->categories->first()->fresh());

        $this->assertNotEmpty($podium);
        $this->assertSame(
            array_map(fn ($r) => $r['team']['name'], array_slice($standings, 0, count($podium))),
            $this->names($podium),
            'the podium must be the top of the same table the Klasemen tab renders',
        );
    }

    /**
     * The endpoint is public, and it carries every finished category of the
     * event while leaving the unfinished ones out.
     */
    public function test_the_public_endpoint_lists_finished_categories_only(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org, 'knockout_single', 4);

        // A second category of the same event, left unplayed.
        $event->categories()->create([
            'name' => 'Putri',
            'slug' => 'putri',
            'tournament_format' => 'knockout_single',
            'registration_fee' => 0,
            'sort_order' => 1,
        ]);

        $url = "/api/v1/public/events/{$org->slug}/{$event->slug}/podium";

        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');

        $this->generate($user, $org, $event);
        $this->playRemaining($user, $org, $event);

        $res = $this->getJson($url)->assertOk();

        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.slug', 'umum');
        $this->assertCount(2, $res->json('data.0.podium'));
    }
}
