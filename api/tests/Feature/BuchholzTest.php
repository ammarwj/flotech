<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\StandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Buchholz: the points your opponents ended up with.
 *
 * Swiss needs it because the record alone lies — 3-0 against the bottom of the
 * field is not 3-0 against the top of it, and with every entrant playing a
 * different schedule there is nothing else to separate two unbeaten teams.
 *
 * Everything is asserted by *comparing*: the same fixtures under two opposite
 * tiebreaker orders, and the new catalog row against a league that never asked
 * for it.
 */
class BuchholzTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /** @var array<string, string> name => team id */
    private array $teams = [];

    private function categoryWith(array $tiebreakers): EventCategory
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org, null, ['sport_type' => 'football', 'status' => 'ongoing']);

        $category = $event->categories()->create([
            'name' => 'Utama',
            'slug' => 'utama',
            'tournament_format' => 'swiss',
            'registration_fee' => 0,
            'sort_order' => 0,
            'bracket_config' => [
                'points' => ['win' => 3, 'draw' => 1, 'lose' => 0],
                'tiebreakers' => $tiebreakers,
            ],
        ]);

        $this->teams = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $name) {
            $this->teams[$name] = $event->teams()->create([
                'category_id' => $category->id,
                'name' => $name,
                'status' => 'approved',
            ])->id;
        }

        return $category;
    }

    private function play(EventCategory $category, int $round, string $home, string $away, int $hs, int $as): void
    {
        $category->matches()->create([
            'event_id' => $category->event_id,
            'stage' => 'swiss',
            'round' => $round,
            'leg' => 1,
            'order' => 0,
            'home_team_id' => $this->teams[$home],
            'away_team_id' => $this->teams[$away],
            'home_score' => $hs,
            'away_score' => $as,
            'status' => 'finished',
            'confirmed_at' => now(),
        ]);
    }

    private function bye(EventCategory $category, int $round, string $team): void
    {
        $category->matches()->create([
            'event_id' => $category->event_id,
            'stage' => 'swiss',
            'round' => $round,
            'leg' => 1,
            'order' => 9,
            'home_team_id' => $this->teams[$team],
            'away_team_id' => null,
            'home_score' => null,
            'away_score' => null,
            'status' => 'finished',
            'confirmed_at' => now(),
        ]);
    }

    /** @return array<string, array<string, mixed>> name => row */
    private function rows(EventCategory $category): array
    {
        $rows = app(StandingService::class)->compute($category->fresh());

        $out = [];
        foreach ($rows as $row) {
            $out[$row['team']['name']] = $row;
        }

        return $out;
    }

    /**
     * A and B finish level on points and never meet, so head to head is mute
     * between them — and then the two columns that could separate them point in
     * opposite directions. A played the half of the field that went on to
     * collect 9 points; B beat its first opponent 5-0 and holds the better goal
     * difference.
     *
     * That opposition is the whole fixture: a pair level on *everything* except
     * buchholz can only ever be ranked one way, so it cannot show that the
     * column is the thing being read. Here each order has a different winner.
     *
     * Six teams rather than four because the pair has to lose to somebody, and
     * with four the loser of A's half is the winner of B's: every schedule ends
     * up the same strength and there is nothing for buchholz to say.
     */
    private function seedEqualRecords(EventCategory $category): void
    {
        // A wins one and loses one by a goal either way: 3 points, goal
        // difference 0.
        $this->play($category, 1, 'A', 'C', 2, 1);
        $this->play($category, 1, 'D', 'A', 2, 1);
        // B does the same, but its win was a rout: 3 points, goal difference +4.
        $this->play($category, 1, 'B', 'E', 5, 0);
        $this->play($category, 1, 'F', 'B', 2, 1);

        // Now the halves separate. A's opponents win theirs (C to 3, D to 6);
        // B's do not (E stays on 0, F on 3).
        $this->play($category, 2, 'C', 'E', 5, 0);
        $this->play($category, 2, 'D', 'F', 5, 0);
    }

    public function test_buchholz_sums_the_points_the_opponents_finished_on(): void
    {
        $category = $this->categoryWith(['buchholz', 'drawing_lots']);
        $this->seedEqualRecords($category);

        $rows = $this->rows($category);

        // A met C (one win, 3) and D (two wins, 6); B met E (none, 0) and F
        // (one, 3).
        $this->assertSame(3, $rows['C']['points']);
        $this->assertSame(6, $rows['D']['points']);
        $this->assertSame(0, $rows['E']['points']);
        $this->assertSame(3, $rows['F']['points']);

        // 3 + 6 against 0 + 3. Compared, because "A has buchholz" proves nothing
        // if B has the same number.
        $this->assertSame(9, $rows['A']['buchholz']);
        $this->assertSame(3, $rows['B']['buchholz']);

        // The record it is breaking really is level — and the one column that
        // could have separated them instead favours B, which is what makes the
        // ranking test below a comparison rather than a coincidence.
        $this->assertSame($rows['A']['points'], $rows['B']['points']);
        $this->assertSame(0, $rows['A']['goal_diff']);
        $this->assertSame(4, $rows['B']['goal_diff']);
    }

    public function test_the_same_fixtures_rank_differently_under_two_tiebreaker_orders(): void
    {
        // Buchholz first: A's harder schedule puts it ahead of B.
        $withBuchholz = $this->categoryWith(['buchholz', 'goal_difference', 'drawing_lots']);
        $this->seedEqualRecords($withBuchholz);
        $ranked = $this->rows($withBuchholz);

        $this->assertLessThan($ranked['B']['rank'], $ranked['A']['rank']);

        // Same results, buchholz dropped: goal difference answers first and B
        // goes ahead. One fixture set, two orders, opposite winners — which is
        // what proves the column is read rather than the names.
        //
        // Head to head is left out of both orders on purpose. A and B never
        // meet, but C and F finish level with them on points, so the block
        // handed to the tiebreakers is four teams wide and a head to head over
        // it splits A from B on matches that have nothing to do with either
        // column under test.
        $without = $this->categoryWith(['goal_difference', 'goals_scored', 'drawing_lots']);
        $this->seedEqualRecords($without);
        $plain = $this->rows($without);

        $this->assertLessThan($plain['A']['rank'], $plain['B']['rank']);
        // Still computed either way — it is the priority that changed, not the
        // column.
        $this->assertSame(9, $plain['A']['buchholz']);
        $this->assertSame(3, $plain['B']['buchholz']);
    }

    public function test_a_bye_contributes_nothing_to_buchholz(): void
    {
        $category = $this->categoryWith(['buchholz', 'drawing_lots']);

        // A beats B, and C sits out. There was no opponent to have points.
        $this->play($category, 1, 'A', 'B', 3, 0);
        $this->bye($category, 1, 'C');

        $rows = $this->rows($category);

        // The bye is worth a played match and a win...
        $this->assertSame(1, $rows['C']['played']);
        $this->assertSame(3, $rows['C']['points']);
        // ...but nothing to measure a schedule against. Compared with A, who
        // played a real opponent in the same round.
        $this->assertSame(0, $rows['C']['buchholz']);
        $this->assertSame(0, $rows['A']['buchholz']); // B finished on 0
        $this->assertSame(3, $rows['B']['buchholz']); // A finished on 3
    }

    public function test_an_existing_league_is_unchanged_by_the_new_catalog_row(): void
    {
        // Same two teams, same results, in a league that never configured a
        // tiebreaker order — which is the case the catalog's own order answers.
        // Buchholz is seeded last, behind drawing_lots, so it is never reached.
        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org, null, ['sport_type' => 'football', 'status' => 'ongoing']);

        $category = $event->categories()->create([
            'name' => 'Liga', 'slug' => 'liga', 'tournament_format' => 'league',
            'registration_fee' => 0, 'sort_order' => 0,
        ]);

        $this->teams = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $name) {
            $this->teams[$name] = $event->teams()->create([
                'category_id' => $category->id, 'name' => $name, 'status' => 'approved',
            ])->id;
        }

        // Stage null throughout, as a league stores it — the same fixtures
        // seedEqualRecords() plays.
        foreach ([
            [1, 'A', 'C', 2, 1], [1, 'D', 'A', 2, 1], [1, 'B', 'E', 5, 0], [1, 'F', 'B', 2, 1],
            [2, 'C', 'E', 5, 0], [2, 'D', 'F', 5, 0],
        ] as [$r, $h, $a, $hs, $as]) {
            $category->matches()->create([
                'event_id' => $event->id, 'stage' => null, 'round' => $r, 'leg' => 1, 'order' => 0,
                'home_team_id' => $this->teams[$h], 'away_team_id' => $this->teams[$a],
                'home_score' => $hs, 'away_score' => $as,
                'status' => 'finished', 'confirmed_at' => now(),
            ]);
        }

        $ranks = fn () => collect($this->rows($category))->map(fn ($row) => $row['rank'])->all();

        // What the catalog answers now that buchholz sits in it.
        $withRow = $ranks();

        // The order this league ran on before the row existed, written out.
        $category->update(['bracket_config' => ['tiebreakers' => [
            'head_to_head', 'goal_difference', 'goals_scored', 'fair_play', 'penalty_shootout', 'drawing_lots',
        ]]]);

        // Identical, every place of it: buchholz is seeded behind drawing_lots,
        // which is already a total order, so nothing reaches it. Compared as a
        // whole table rather than one pair — a tiebreaker that slipped in early
        // would reorder somewhere, not necessarily at the top.
        $this->assertSame($ranks(), $withRow);

        // And the column is filled all the same, so a league that *does* ask for
        // it gets a real number.
        $rows = $this->rows($category);
        $this->assertSame(9, $rows['A']['buchholz']);
        $this->assertSame(3, $rows['B']['buchholz']);
    }

    public function test_the_swiss_format_default_puts_buchholz_first(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org, null, ['sport_type' => 'football']);

        $category = $event->categories()->create([
            'name' => 'Utama', 'slug' => 'utama', 'tournament_format' => 'swiss',
            'registration_fee' => 0, 'sort_order' => 0,
        ]);

        $swiss = \App\Support\HybridConfig::fromCategory($category);

        // Compared with a league category on the same event: the catalog order
        // trails the lot, the Swiss preset leads with buchholz.
        $league = $event->categories()->create([
            'name' => 'Liga', 'slug' => 'liga', 'tournament_format' => 'league',
            'registration_fee' => 0, 'sort_order' => 1,
        ]);

        $this->assertSame('buchholz', \App\Services\Catalog::formatDefaults('swiss')['tiebreakers'][0]);
        $this->assertNotSame('buchholz', \App\Support\HybridConfig::fromCategory($league)->tiebreakers[0]);
    }
}
