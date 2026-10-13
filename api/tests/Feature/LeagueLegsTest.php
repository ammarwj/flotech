<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Home & away for a standalone league — the same `legs` the hybrid group stage
 * uses, stored in the same `bracket_config` and expanded by the same
 * ScheduleService::legRounds().
 *
 * Everything here *compares* a single-leg league with a double-leg one on the
 * same four entrants. Asserting "12 fixtures" alone proves nothing: it would
 * still pass if legRounds() doubled every league, which is exactly the bug a
 * one-sided assertion hides.
 */
class LeagueLegsTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /**
     * A four-team league category on the given bracket_config.
     *
     * @param  array<string, mixed>  $config
     */
    private function league(Organization $org, array $config): EventCategory
    {
        $event = $this->eventOn($org, null, [
            'sport_type' => 'futsal',
            'start_date' => '2026-09-01',
            'end_date' => '2026-10-30',
        ]);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
            'bracket_config' => $config,
        ]);

        foreach (range(1, 4) as $i) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => "Team {$i}",
                'status' => 'approved',
            ]);
        }

        return $category;
    }

    private function generate(User $owner, EventCategory $category): void
    {
        $event = $category->event;

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$event->organization_id}/events/{$event->id}/categories/{$category->id}/schedule")
            ->assertCreated();
    }

    public function test_a_double_leg_league_plays_every_pairing_twice(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        $single = $this->league($org, ['home_away' => false, 'legs' => 1]);
        $double = $this->league($org, ['home_away' => true, 'legs' => 2]);

        $this->generate($owner, $single);
        $this->generate($owner, $double);

        // 4 teams: 6 pairings once, 12 twice. The pair of numbers is the point —
        // one of them alone cannot tell "legs is read" from "legs is ignored".
        $this->assertSame(6, $single->matches()->count());
        $this->assertSame(12, $double->matches()->count());

        // Only the second leg carries leg 2, and it reverses the venue: the same
        // two sides meet once at each end. A count alone would pass on a
        // generator that merely replayed the first leg unchanged.
        $this->assertSame(0, $single->matches()->where('leg', 2)->count());
        $this->assertSame(6, $double->matches()->where('leg', 2)->count());

        $first = $double->matches()->where('leg', 1)->orderBy('round')->orderBy('order')->first();

        $this->assertSame(1, $double->matches()
            ->where('leg', 2)
            ->where('home_team_id', $first->away_team_id)
            ->where('away_team_id', $first->home_team_id)
            ->count());
    }

    /**
     * The organizer's own `legs` is what runs, not a value derived from the
     * checkbox beside it: HybridConfig only fills in the half that is missing.
     */
    public function test_an_explicit_single_leg_beats_the_home_away_flag(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        $category = $this->league($org, ['home_away' => true, 'legs' => 1]);

        $this->generate($owner, $category);

        $this->assertSame(6, $category->matches()->count());
    }

    /**
     * `home_away` with no `legs` beside it still plays twice — that is the pair
     * a catalog preset may ship, and what an older stored config looks like.
     */
    public function test_home_away_alone_still_doubles_the_schedule(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        $category = $this->league($org, ['home_away' => true]);

        $this->generate($owner, $category);

        $this->assertSame(12, $category->matches()->count());
    }

    /** The setting survives a round trip through the event form. */
    public function test_the_organizer_can_turn_home_away_on_for_a_league(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        $category = $this->league($org, ['home_away' => false, 'legs' => 1]);
        $event = $category->event;

        $this->actingAs($owner, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}", [
                'categories' => [[
                    'id' => $category->id,
                    'name' => 'Umum',
                    'tournament_format' => 'league',
                    'bracket_config' => ['home_away' => true, 'legs' => 2],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.categories.0.bracket_config.legs', 2)
            ->assertJsonPath('data.categories.0.bracket_config.home_away', true);

        $this->generate($owner, $category);

        $this->assertSame(12, $category->matches()->count());
    }

    /**
     * Regenerating after switching back wipes the return fixtures rather than
     * leaving them orphaned — generateRoundRobin() deletes first, and a league
     * that drops to one leg has to end up with a one-leg table.
     */
    public function test_switching_back_to_one_leg_rebuilds_the_schedule(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        $category = $this->league($org, ['home_away' => true, 'legs' => 2]);

        $this->generate($owner, $category);
        $this->assertSame(12, $category->matches()->count());

        $category->update(['bracket_config' => ['home_away' => false, 'legs' => 1]]);

        $this->generate($owner, $category->fresh());

        $this->assertSame(6, $category->matches()->count());
        $this->assertSame(0, $category->matches()->where('leg', 2)->count());
    }
}
