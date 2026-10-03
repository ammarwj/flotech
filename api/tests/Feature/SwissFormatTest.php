<?php

namespace Tests\Feature;

use App\Models\EventCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Swiss, through the endpoints the organizer actually presses.
 *
 * Everything is asserted by *comparing* two states of the same category: the
 * pairings of round 1 against round 2, the gate before and after a result is
 * confirmed, the fixture ids before and after a refused regenerate. "Round 2
 * exists" and "the request was refused" are both true of a badly broken
 * implementation.
 */
class SwissFormatTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private User $owner;

    private Organization $org;

    /** @var array<string, string> name => team id */
    private array $teams = [];

    /** @param  array<string, mixed>  $config */
    private function category(array $config = [], int $count = 8): EventCategory
    {
        $this->owner = User::factory()->create();
        $this->org = $this->orgFor($this->owner);
        $event = $this->eventOn($this->org, null, [
            'status' => 'ongoing',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $category = $event->categories()->create([
            'name' => 'Utama',
            'slug' => 'utama',
            'participant_type' => 'team',
            'tournament_format' => 'swiss',
            'registration_fee' => 0,
            'sort_order' => 0,
            'bracket_config' => $config ?: null,
        ]);

        $this->teams = [];
        foreach (array_slice(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'], 0, $count) as $name) {
            $this->teams[$name] = $event->teams()->create([
                'category_id' => $category->id,
                'name' => $name,
                'status' => 'approved',
            ])->id;
        }

        return $category;
    }

    private function url(EventCategory $category, string $suffix = ''): string
    {
        return "/api/v1/organizations/{$this->org->id}/events/{$category->event_id}"
            ."/categories/{$category->id}/{$suffix}";
    }

    private function buildRound1(EventCategory $category): void
    {
        $this->actingAs($this->owner, 'api')
            ->postJson($this->url($category, 'schedule'))
            ->assertCreated();
    }

    /**
     * Settle a round. `$confirm` false leaves the scores in but the result
     * unconfirmed — the state that separates "played" from "official".
     */
    private function finish(EventCategory $category, int $round, bool $confirm = true): void
    {
        $category->matches()->where('round', $round)->whereNotNull('away_team_id')->get()
            ->each(fn ($match) => $match->update([
                'home_score' => 2,
                'away_score' => 1,
                'status' => 'finished',
                'confirmed_at' => $confirm ? now() : null,
            ]));
    }

    /** @return array<int, string> order-free pair keys of a round */
    private function pairs(EventCategory $category, int $round): array
    {
        return $category->matches()
            ->where('round', $round)
            ->whereNotNull('away_team_id')
            ->get()
            ->map(fn ($m) => \App\Support\SwissPairing::pairKey($m->home_team_id, $m->away_team_id))
            ->sort()
            ->values()
            ->all();
    }

    public function test_the_second_round_pairs_the_table_rather_than_repeating_the_first(): void
    {
        $category = $this->category();

        $this->buildRound1($category);
        $this->finish($category, 1);

        $this->actingAs($this->owner, 'api')
            ->postJson($this->url($category, 'swiss/rounds'))
            ->assertCreated();

        $first = $this->pairs($category, 1);
        $second = $this->pairs($category, 2);

        // A full round either way...
        $this->assertCount(4, $first);
        $this->assertCount(4, $second);
        // ...and not one pairing in common. Compared rather than counted: four
        // fixtures in round 2 is also what a second copy of round 1 looks like.
        $this->assertSame([], array_intersect($first, $second));
    }

    public function test_the_pairing_follows_the_standings_and_not_the_entry_list(): void
    {
        $category = $this->category();

        $this->buildRound1($category);

        // Every home side wins, so round 1's four winners are the top four of
        // the table and Swiss pairs them against each other.
        $this->finish($category, 1);

        $winners = $category->matches()->where('round', 1)->whereNotNull('away_team_id')
            ->pluck('home_team_id')->all();
        $losers = $category->matches()->where('round', 1)->whereNotNull('away_team_id')
            ->pluck('away_team_id')->all();

        $this->actingAs($this->owner, 'api')
            ->postJson($this->url($category, 'swiss/rounds'))
            ->assertCreated();

        // The two competing predictions: paired by record (winner v winner) or
        // paired by the order the entrants were created in (which round 1 used).
        // Only one of them holds, and that is what proves the table is read.
        foreach ($category->matches()->where('round', 2)->whereNotNull('away_team_id')->get() as $match) {
            $both = [$match->home_team_id, $match->away_team_id];

            $this->assertTrue(
                count(array_intersect($both, $winners)) === 2 || count(array_intersect($both, $losers)) === 2,
                'A 3-point team was paired with a 0-point team while equal records were still available.',
            );
        }
    }

    public function test_the_gate_reads_confirmation_and_not_just_a_scoreline(): void
    {
        $category = $this->category();
        $this->buildRound1($category);

        $state = fn () => $this->actingAs($this->owner, 'api')
            ->getJson($this->url($category, 'swiss'))->json('data');

        // Nothing played.
        $fresh = $state();
        $this->assertFalse($fresh['can_add_round']);
        $this->assertSame(4, $fresh['round_matches_pending']);

        // Scores in, nothing confirmed — the state that separates the two
        // halves of the predicate. Still blocked.
        $this->finish($category, 1, confirm: false);
        $scored = $state();
        $this->assertFalse($scored['can_add_round']);
        $this->assertSame(4, $scored['round_matches_pending']);

        // Confirmed: open. Compared with the step above, because "blocked while
        // unplayed, open when confirmed" passes just as well for a gate that
        // only reads `status`.
        $this->finish($category, 1, confirm: true);
        $official = $state();
        $this->assertTrue($official['can_add_round']);
        $this->assertSame(0, $official['round_matches_pending']);
        $this->assertSame(2, $official['next_round']);
    }

    public function test_zero_pending_means_two_opposite_things(): void
    {
        $empty = $this->category();
        $emptyState = $this->actingAs($this->owner, 'api')
            ->getJson($this->url($empty, 'swiss'))->json('data');

        $played = $this->category();
        $this->buildRound1($played);
        $this->finish($played, 1);
        $playedState = $this->actingAs($this->owner, 'api')
            ->getJson($this->url($played, 'swiss'))->json('data');

        // Identical pending counts, opposite answers — which is the whole reason
        // `round_matches_total` is published beside it.
        $this->assertSame(0, $emptyState['round_matches_pending']);
        $this->assertSame(0, $playedState['round_matches_pending']);
        $this->assertSame(0, $emptyState['round_matches_total']);
        $this->assertSame(4, $playedState['round_matches_total']);
        $this->assertFalse($emptyState['can_add_round']);
        $this->assertTrue($playedState['can_add_round']);
    }

    public function test_a_round_the_category_is_not_owed_is_refused(): void
    {
        $category = $this->category();
        $this->buildRound1($category);
        $this->finish($category, 1);

        // A double-clicked button sends the round it read a moment ago. The
        // second press must not build round 3 off a table that has not moved.
        $this->actingAs($this->owner, 'api')
            ->postJson($this->url($category, 'swiss/rounds'), ['round' => 2])
            ->assertCreated();

        $this->actingAs($this->owner, 'api')
            ->postJson($this->url($category, 'swiss/rounds'), ['round' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.feature', 'swiss_round_mismatch');

        // Compared on the count, not on the status code: a refusal that still
        // wrote the round would pass the assertion above.
        $this->assertSame(2, (int) $category->matches()->where('stage', 'swiss')->max('round'));
    }

    public function test_regenerating_is_refused_once_anything_has_been_played(): void
    {
        $category = $this->category();
        $this->buildRound1($category);

        $ids = $category->matches()->orderBy('id')->pluck('id')->all();

        // Clean: "Buat Jadwal" is still allowed to rebuild round 1.
        $this->actingAs($this->owner, 'api')->postJson($this->url($category, 'schedule'))->assertCreated();
        $this->assertNotSame($ids, $category->matches()->orderBy('id')->pluck('id')->all());

        // Once a score exists it refuses — and the fixtures that existed are
        // still the same rows. Compared on ids, because a wipe-and-rebuild
        // leaves the same *number* of matches behind.
        $this->finish($category, 1);
        $kept = $category->matches()->orderBy('id')->pluck('id')->all();

        $this->actingAs($this->owner, 'api')
            ->postJson($this->url($category, 'schedule'))
            ->assertStatus(422)
            ->assertJsonPath('errors.feature', 'swiss_regenerate');

        $this->assertSame($kept, $category->matches()->orderBy('id')->pluck('id')->all());
    }

    public function test_only_the_last_round_comes_off_and_only_while_it_is_result_free(): void
    {
        $category = $this->category();
        $this->buildRound1($category);
        $this->finish($category, 1);

        $this->actingAs($this->owner, 'api')
            ->postJson($this->url($category, 'swiss/rounds'))->assertCreated();

        $roundOne = $category->matches()->where('round', 1)->orderBy('id')->pluck('id')->all();

        // Round 2 is result-free, so it goes — and round 1 is untouched.
        $this->actingAs($this->owner, 'api')
            ->deleteJson($this->url($category, 'swiss/rounds/last'))
            ->assertOk();

        $this->assertSame(0, $category->matches()->where('round', 2)->count());
        $this->assertSame($roundOne, $category->matches()->where('round', 1)->orderBy('id')->pluck('id')->all());

        // Round 1 has results, so now the same request is refused. Compared with
        // the success above: a delete that always refused would pass on its own.
        $this->actingAs($this->owner, 'api')
            ->deleteJson($this->url($category, 'swiss/rounds/last'))
            ->assertStatus(422)
            ->assertJsonPath('errors.feature', 'swiss_round_has_results');

        $this->assertSame(4, $category->matches()->where('round', 1)->count());
    }

    public function test_the_planned_round_count_is_what_stops_the_rounds(): void
    {
        // Two rounds planned for a field that could play seven.
        $category = $this->category(['swiss_rounds' => 2]);

        $this->buildRound1($category);
        $this->finish($category, 1);

        $this->actingAs($this->owner, 'api')
            ->postJson($this->url($category, 'swiss/rounds'))->assertCreated();
        $this->finish($category, 2);

        $this->actingAs($this->owner, 'api')
            ->postJson($this->url($category, 'swiss/rounds'))
            ->assertStatus(422)
            ->assertJsonPath('errors.feature', 'swiss_rounds');

        // The same category, one more round planned, and the request that was
        // just refused succeeds — which is what shows the setting is read and
        // not some other exhaustion.
        $category->update(['bracket_config' => ['swiss_rounds' => 3]]);

        $this->actingAs($this->owner, 'api')
            ->postJson($this->url($category, 'swiss/rounds'))
            ->assertCreated();
    }

    public function test_a_draw_is_a_result_in_swiss_though_a_knockout_refuses_one(): void
    {
        $category = $this->category();
        $this->buildRound1($category);

        $match = $category->matches()->whereNotNull('away_team_id')->first();

        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/v1/organizations/{$this->org->id}/matches/{$match->id}", [
                'home_score' => 1, 'away_score' => 1, 'status' => 'finished',
            ])
            ->assertOk();

        // Same scoreline in a knockout category, which has to produce a winner.
        // The comparison is the point: "swiss accepts 1-1" says nothing unless
        // something else refuses it.
        $knockout = $category->event->categories()->create([
            'name' => 'Piala', 'slug' => 'piala', 'participant_type' => 'team',
            'tournament_format' => 'knockout_single', 'registration_fee' => 0, 'sort_order' => 1,
        ]);

        $a = $category->event->teams()->create(['category_id' => $knockout->id, 'name' => 'X', 'status' => 'approved']);
        $b = $category->event->teams()->create(['category_id' => $knockout->id, 'name' => 'Y', 'status' => 'approved']);

        $tie = $knockout->matches()->create([
            'event_id' => $category->event_id, 'round' => 1, 'leg' => 1, 'order' => 0,
            'home_team_id' => $a->id, 'away_team_id' => $b->id, 'status' => 'scheduled',
        ]);

        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/v1/organizations/{$this->org->id}/matches/{$tie->id}", [
                'home_score' => 1, 'away_score' => 1, 'status' => 'finished',
            ])
            ->assertStatus(422);
    }

    public function test_the_swiss_endpoints_refuse_a_category_of_another_format(): void
    {
        $category = $this->category();
        $category->update(['tournament_format' => 'league']);

        $this->actingAs($this->owner, 'api')
            ->getJson($this->url($category, 'swiss'))->assertStatus(422);
        $this->actingAs($this->owner, 'api')
            ->postJson($this->url($category, 'swiss/rounds'))->assertStatus(422);
        $this->actingAs($this->owner, 'api')
            ->deleteJson($this->url($category, 'swiss/rounds/last'))->assertStatus(422);
    }
}
