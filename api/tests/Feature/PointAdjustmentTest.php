<?php

namespace Tests\Feature;

use App\Exports\StandingsExport;
use App\Models\EventCategory;
use App\Models\User;
use App\Services\StandingService;
use App\Services\SwissService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Manual point adjustments: a house rule the fixtures cannot express ("suporter
 * datang lengkap = +2 poin, sebagian = +1"), or a sanction docking a squad.
 *
 * The mirror image of the decider in TiebreakPlayoffTest. A decider moves rows
 * and never numbers; an adjustment moves exactly *two* things — a row's points
 * and a row's place — and nothing else at all. Not played, not won, not goals,
 * not sets, not the buchholz of the team it happened to beat, not the
 * head-to-head table it sits inside.
 *
 * So the central test here does not assert "the adjusted team is first". It
 * snapshots every column of every row, applies the adjustment, and demands that
 * exactly the two expected ones moved. Asserting the new order alone would pass
 * just as happily if an adjustment were quietly being counted as a match.
 */
class PointAdjustmentTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /** @var array<string, string> name => team id */
    private array $teams = [];

    /**
     * A category with the named entrants, all approved.
     *
     * @param  array<int, string>  $names
     * @param  array<string, mixed>  $category  overrides for the category row
     */
    private function category(array $names, array $category = []): EventCategory
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org, null, ['sport_type' => 'football', 'status' => 'ongoing']);

        $model = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum-'.uniqid(),
            'participant_type' => 'team',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
            ...$category,
        ]);

        $this->teams = [];
        foreach ($names as $name) {
            $this->teams[$name] = $event->teams()->create([
                'category_id' => $model->id,
                'name' => $name,
                'status' => 'approved',
            ])->id;
        }

        return $model;
    }

    private function play(EventCategory $category, string $home, string $away, int $hs, int $as, int $round = 1, ?string $stage = null): void
    {
        $category->matches()->create([
            'event_id' => $category->event_id,
            'stage' => $stage,
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

    private function adjust(EventCategory $category, string $team, int $points, string $reason = 'Suporter lengkap'): void
    {
        $category->adjustments()->create([
            'team_id' => $this->teams[$team],
            'points' => $points,
            'reason' => $reason,
        ]);
    }

    /**
     * The spreadsheet is the copy that gets printed and argued over, and its
     * heading row and value rows are computed by two separate methods — so the
     * only thing keeping them honest is that they stay the same length and the
     * same order.
     *
     * Compared across a category **with** an adjustment and one **without**: the
     * adjusted one alone would pass if the heading were inserted in place but
     * the value appended at the end, since with a single adjustment the result
     * still looks plausible. The unadjusted category is what catches `Fair Play`
     * sliding one cell to the left.
     */
    public function test_the_export_adjustment_heading_and_its_column_stay_in_step(): void
    {
        $service = app(StandingService::class);

        $adjusted = $this->category(['Arema', 'Bali']);
        $this->play($adjusted, 'Arema', 'Bali', 2, 0);
        $this->adjust($adjusted, 'Bali', 4);

        $plain = $this->category(['Persija', 'Persib']);
        $this->play($plain, 'Persija', 'Persib', 1, 0);

        foreach ([$adjusted, $plain] as $category) {
            $export = new StandingsExport($category->fresh(), $service);
            $headings = $export->headings();
            $rows = $export->rows();

            $adj = array_search('Adj', $headings, true);
            $points = array_search('Poin', $headings, true);
            $fairPlay = array_search('Fair Play', $headings, true);

            $this->assertNotFalse($adj, 'The column is printed for every category, adjusted or not.');
            $this->assertSame($points + 1, $adj, 'Adj belongs beside the number it explains.');
            $this->assertSame($adj + 1, $fairPlay);

            foreach ($rows as $row) {
                $this->assertCount(
                    count($headings),
                    $row,
                    'A row shorter or longer than the heading row prints every value in the wrong column.',
                );
            }

            // And the values sit under their own headings, which is the half a
            // length check cannot see.
            $leader = $rows[0];
            $expected = collect($service->compute($category->fresh()))->firstWhere('rank', 1);

            $this->assertSame($expected['adjustment'], $leader[$adj]);
            $this->assertSame($expected['points'], $leader[$points]);
            $this->assertSame($expected['fair_play'], $leader[$fairPlay]);
        }
    }

    /**
     * The reason travels with the number, in the order it was typed.
     *
     * This is the half that makes the feature legible on the public table,
     * which reads this same payload and has no ledger to open. Compared against
     * a team with no entries, because asserting one row's notes would pass if
     * every row carried the same list — and against the total, because notes
     * that disagree with the number they explain are worse than none.
     */
    public function test_the_reasons_behind_a_total_are_published_with_it(): void
    {
        $category = $this->category(['Arema', 'Bali']);

        $this->adjust($category, 'Arema', 2, 'Suporter lengkap matchday 3');
        $this->adjust($category, 'Arema', -3, 'Sanksi walkout');

        $rows = $this->rows($category);

        $this->assertSame(
            [
                ['points' => 2, 'reason' => 'Suporter lengkap matchday 3'],
                ['points' => -3, 'reason' => 'Sanksi walkout'],
            ],
            $rows['Arema']['adjustment_notes'],
            'Oldest first, each with the reason it was typed with.',
        );

        // The notes and the number are two views of one ledger, so they cannot
        // be allowed to disagree.
        $this->assertSame(
            array_sum(array_column($rows['Arema']['adjustment_notes'], 'points')),
            $rows['Arema']['adjustment'],
        );

        $this->assertSame([], $rows['Bali']['adjustment_notes']);
        $this->assertSame(0, $rows['Bali']['adjustment']);
    }

    /** @return array<string, array<string, mixed>> name => row */
    private function rows(EventCategory $category): array
    {
        $out = [];
        foreach (app(StandingService::class)->compute($category->fresh()) as $row) {
            $out[$row['team']['name']] = $row;
        }

        return $out;
    }

    /**
     * The central one. Every column is snapshotted before the ledger row exists
     * and compared after, so the test states what an adjustment may *not* touch
     * rather than only what it may.
     *
     * Asserting `points === 8` alone would pass if applyAdjustments() also bumped
     * `played`, or routed through applyResult() with an invented scoreline — the
     * exact mistake applyBye()'s docblock exists to warn about.
     */
    public function test_an_adjustment_moves_points_and_rank_and_nothing_else(): void
    {
        $category = $this->category(['Persija', 'Persib']);
        $this->play($category, 'Persija', 'Persib', 2, 0);

        $before = $this->rows($category);
        $this->assertSame(1, $before['Persija']['rank']);
        $this->assertSame(2, $before['Persib']['rank']);

        // Enough to put the loser above the winner: 0 + 4 beats 3 + 0.
        $this->adjust($category, 'Persib', 4);

        $after = $this->rows($category);

        // The two things that are allowed to move.
        $this->assertSame(4, $after['Persib']['points']);
        $this->assertSame(4, $after['Persib']['adjustment']);
        $this->assertSame(1, $after['Persib']['rank']);
        $this->assertSame(2, $after['Persija']['rank']);

        // And everything that is not.
        $frozen = [
            'played', 'won', 'drawn', 'lost',
            'goals_for', 'goals_against', 'goal_diff',
            'sets_for', 'sets_against', 'set_diff',
            'points_for', 'points_against', 'points_diff',
            'match_points', 'fair_play', 'buchholz',
        ];

        foreach (['Persija', 'Persib'] as $name) {
            foreach ($frozen as $column) {
                $this->assertSame(
                    $before[$name][$column],
                    $after[$name][$column],
                    "{$column} of {$name} moved, and an adjustment is not a match.",
                );
            }
        }

        // The published identity, so no client needs to add the two itself.
        foreach ($after as $row) {
            $this->assertSame($row['match_points'] + $row['adjustment'], $row['points']);
        }

        // The untouched row keeps its three points and no adjustment at all.
        $this->assertSame(3, $after['Persija']['points']);
        $this->assertSame(0, $after['Persija']['adjustment']);
    }

    /**
     * Strength of schedule is a statement about results, and a house rule is not
     * a result. Compared across two identical Swiss categories because the leak
     * shows up on the *opponents'* rows, never on the adjusted one: A's own
     * buchholz is a function of everyone else's points, so asserting it alone
     * misses the bug entirely.
     *
     * This is also the only test that fails if someone drops the `match_points`
     * snapshot and points applyBuchholz() back at `points`.
     */
    public function test_an_adjustment_does_not_feed_its_opponents_buchholz(): void
    {
        $build = function (bool $adjusted): array {
            $category = $this->category(['A', 'B', 'C', 'D'], ['tournament_format' => 'swiss']);

            // A beats both its opponents; B and C each carry a loss to A.
            $this->play($category, 'A', 'B', 2, 0, 1, 'swiss');
            $this->play($category, 'C', 'D', 1, 0, 1, 'swiss');
            $this->play($category, 'A', 'C', 1, 0, 2, 'swiss');
            $this->play($category, 'B', 'D', 2, 1, 2, 'swiss');

            if ($adjusted) {
                $this->adjust($category, 'A', 5);
            }

            return $this->rows($category);
        };

        $plain = $build(false);
        $bonus = $build(true);

        // The adjustment demonstrably ran.
        $this->assertSame(5, $bonus['A']['adjustment']);
        $this->assertSame($plain['A']['points'] + 5, $bonus['A']['points']);

        // And it reached nobody's strength of schedule — least of all the two
        // teams A played, which is where it would surface.
        foreach (['B', 'C', 'D', 'A'] as $name) {
            $this->assertSame(
                $plain[$name]['buchholz'],
                $bonus[$name]['buchholz'],
                "buchholz of {$name} moved, so an adjustment leaked into strength of schedule.",
            );
        }
    }

    /**
     * Head to head builds its own table from the matches the tied teams played
     * against each other, so it is structurally immune — there is no guard code,
     * only this test.
     *
     * The adjustment has to be sized to **create** the tie, not break one. A
     * "+5 lifts a team to the top" scenario never reaches miniLeague() at all,
     * because the adjustment removes that team from the tied block it would have
     * been resolved inside.
     */
    public function test_an_adjustment_does_not_feed_the_head_to_head_mini_league(): void
    {
        $category = $this->category(['Arema', 'Bali'], [
            'bracket_config' => [
                'points' => ['win' => 3, 'draw' => 1, 'lose' => 0],
                'tiebreakers' => ['head_to_head', 'goal_difference'],
            ],
        ]);

        // Bali beat Arema, so head to head says Bali. Arema out-scored a third
        // party it never faced, so goal difference would say Arema.
        $this->play($category, 'Bali', 'Arema', 1, 0);

        $before = $this->rows($category);
        $this->assertSame(1, $before['Bali']['rank'], 'Bali leads on points, nothing tied yet.');

        // Now level on 3 each — which is the only state that runs miniLeague().
        $this->adjust($category, 'Arema', 3);

        $after = $this->rows($category);
        $this->assertSame(3, $after['Arema']['points']);
        $this->assertSame(3, $after['Bali']['points']);

        // The match they played decides it. Had the +3 reached the mini table,
        // Arema would hold 3 there against Bali's 3 and the order would flip to
        // goal difference, where Arema is ahead.
        $this->assertSame(1, $after['Bali']['rank'], 'Head to head must read the match, not the ledger.');
        $this->assertSame(2, $after['Arema']['rank']);
    }

    /**
     * Both directions in one test, because either alone is vacuous: direction 1
     * passes if `needs_decider` were hardcoded false, direction 2 if it were
     * hardcoded true.
     */
    public function test_an_adjustment_both_settles_and_creates_a_decider_debt(): void
    {
        $tiebreakers = [
            'bracket_config' => [
                'points' => ['win' => 3, 'draw' => 1, 'lose' => 0],
                'tiebreakers' => ['penalty_shootout', 'drawing_lots'],
            ],
        ];

        // Direction 1: a dead heat the lot is holding apart, settled by +1.
        $level = $this->category(['Persija', 'Persib'], $tiebreakers);
        $this->play($level, 'Persija', 'Persib', 1, 1);

        $before = $this->rows($level);
        $this->assertTrue($before['Persija']['needs_decider']);
        $this->assertTrue($before['Persib']['needs_decider']);

        $this->adjust($level, 'Persija', 1);

        $after = $this->rows($level);
        $this->assertFalse($after['Persija']['needs_decider'], 'Separated on points — nothing is owed.');
        $this->assertFalse($after['Persib']['needs_decider']);

        // Direction 2: a settled pair pushed level, which files the debt.
        $apart = $this->category(['Arema', 'Bali'], $tiebreakers);
        $this->play($apart, 'Arema', 'Bali', 2, 0);

        $beforeApart = $this->rows($apart);
        $this->assertFalse($beforeApart['Arema']['needs_decider']);
        $this->assertFalse($beforeApart['Bali']['needs_decider']);

        $this->adjust($apart, 'Bali', 3);

        $afterApart = $this->rows($apart);
        $this->assertTrue($afterApart['Arema']['needs_decider'], 'Level on points with nothing left to split them.');
        $this->assertTrue($afterApart['Bali']['needs_decider']);
    }

    /**
     * A team that has not played is level with everybody by accident, so
     * markUndecided() gates on `played`. An adjustment must not talk it out of
     * that: it is not a match.
     */
    public function test_an_adjustment_alone_owes_nobody_a_decider(): void
    {
        $category = $this->category(['Arema', 'Bali'], [
            'bracket_config' => [
                'points' => ['win' => 3, 'draw' => 1, 'lose' => 0],
                'tiebreakers' => ['penalty_shootout', 'drawing_lots'],
            ],
        ]);

        $this->adjust($category, 'Arema', 2);

        foreach ($this->rows($category) as $row) {
            $this->assertSame(0, $row['played']);
            $this->assertFalse($row['needs_decider']);
        }
    }

    /**
     * Several entries per team summed. Compared against a team whose entries
     * cancel and one with no entries at all — a single `+2` row cannot tell
     * "sums the ledger" apart from "reads the first row" or "reads the last".
     */
    public function test_several_entries_sum_and_the_sign_survives(): void
    {
        $category = $this->category(['Arema', 'Bali', 'Persija']);

        $this->adjust($category, 'Arema', 2, 'Suporter lengkap matchday 3');
        $this->adjust($category, 'Arema', 1, 'Suporter sebagian matchday 4');
        $this->adjust($category, 'Arema', -3, 'Sanksi');

        $this->adjust($category, 'Bali', 2, 'Suporter lengkap');
        $this->adjust($category, 'Bali', -3, 'Sanksi');

        $rows = $this->rows($category);

        $this->assertSame(0, $rows['Arema']['adjustment'], '2 + 1 - 3 = 0, not 2 and not -3.');
        $this->assertSame(-1, $rows['Bali']['adjustment']);
        $this->assertSame(0, $rows['Persija']['adjustment'], 'No ledger row means no adjustment, not null.');

        foreach ($rows as $row) {
            $this->assertSame($row['match_points'] + $row['adjustment'], $row['points']);
        }
    }

    /**
     * Nothing is clamped. Compared against an untouched row on the same zero,
     * and including the ranking: `points === -3` on its own would pass under a
     * clamp-to-zero implementation if the comparison row were also 0, so it is
     * the order that proves the negative is live inside usort().
     */
    public function test_a_negative_total_is_published_and_ranked_unclamped(): void
    {
        $category = $this->category(['Arema', 'Bali']);
        $this->adjust($category, 'Arema', -3, 'Walkout');

        $rows = $this->rows($category);

        $this->assertSame(-3, $rows['Arema']['points'], 'A docked squad sits below zero; the table must show it.');
        $this->assertSame(-3, $rows['Arema']['adjustment']);
        $this->assertSame(0, $rows['Bali']['points']);
        $this->assertSame(2, $rows['Arema']['rank'], 'Below the untouched team, which is what -3 means.');
        $this->assertSame(1, $rows['Bali']['rank']);
    }

    /**
     * Hybrid qualification rides on the same rows, so an adjustment that turns a
     * group over turns its knockout seeding over with it. Compared with and
     * without the entry, because "A1 is held by Arema" after the fact proves
     * nothing without the before.
     */
    public function test_adjustments_reach_knockout_seeding(): void
    {
        $build = function (bool $adjusted): array {
            $category = $this->category(['Arema', 'Bali', 'Persija', 'Persib'], [
                'tournament_format' => 'hybrid',
                'bracket_config' => [
                    'groups' => 2,
                    'teams_per_group' => 2,
                    'points' => ['win' => 3, 'draw' => 1, 'lose' => 0],
                    'qualification' => ['top_per_group' => 1, 'best_runners_up' => 1],
                ],
            ]);

            foreach (['Arema' => 'A', 'Bali' => 'A', 'Persija' => 'B', 'Persib' => 'B'] as $name => $group) {
                $category->teams()->whereKey($this->teams[$name])->update(['group_name' => $group]);
            }

            $this->play($category, 'Arema', 'Bali', 2, 0, 1, 'group');
            $this->play($category, 'Persija', 'Persib', 1, 0, 1, 'group');

            if ($adjusted) {
                $this->adjust($category, 'Bali', 4);
            }

            $service = app(StandingService::class);
            $category = $category->fresh();

            $slots = collect($service->qualifierSlots($category))
                ->mapWithKeys(fn (array $slot) => [$slot['key'] => $slot['team']['name'] ?? null])
                ->all();

            // qualifiers() hands back team *ids*, so name them to keep the
            // failure message readable.
            $names = array_flip($this->teams);

            return [
                'slots' => $slots,
                'qualifiers' => array_map(fn (string $id) => $names[$id], $service->qualifiers($category)),
            ];
        };

        $plain = $build(false);
        $bonus = $build(true);

        $this->assertSame('Arema', $plain['slots']['A1'], 'Arema wins the group on the pitch.');
        $this->assertSame('Bali', $bonus['slots']['A1'], 'The ledger turned the group over.');

        // And the extra place ranked across groups by crossGroupOrder(), which
        // is rank()'s second caller and easy to miss.
        $this->assertNotSame($plain['slots']['BR1'], $bonus['slots']['BR1']);
        $this->assertNotSame($plain['qualifiers'], $bonus['qualifiers']);
    }

    /**
     * Swiss pairs the next round off the current table, so adjustments move who
     * meets whom. Compared across two identical categories because round 1
     * shuffles — any single-scenario assertion about pairingOrder() is
     * meaningless on its own.
     */
    public function test_adjustments_reach_swiss_pairing_order(): void
    {
        $build = function (bool $adjusted): array {
            $category = $this->category(['A', 'B', 'C', 'D'], ['tournament_format' => 'swiss']);

            $this->play($category, 'A', 'B', 3, 0, 1, 'swiss');
            $this->play($category, 'C', 'D', 1, 0, 1, 'swiss');

            if ($adjusted) {
                // B lost but is handed more than a win is worth, so it must
                // outrank the two teams that actually won.
                $this->adjust($category, 'B', 5);
            }

            $names = array_flip($this->teams);

            return array_map(
                fn (string $id) => $names[$id],
                app(SwissService::class)->pairingOrder($category->fresh()),
            );
        };

        $plain = $build(false);
        $bonus = $build(true);

        $this->assertSame('B', $bonus[0], 'The ledger put B on top of the table it pairs from.');
        $this->assertNotSame($plain, $bonus);
    }

    /**
     * An entry for a team that is no longer approved stays out of the table —
     * rows() filters on status, and nothing here may work around that. The
     * endpoint half of this (the row remains visible and deletable) is in
     * PointAdjustmentApiTest.
     */
    public function test_a_team_that_is_not_approved_keeps_its_ledger_out_of_the_table(): void
    {
        $category = $this->category(['Arema', 'Bali']);
        $this->play($category, 'Arema', 'Bali', 1, 0);

        $this->adjust($category, 'Bali', 5);
        $before = $this->rows($category);
        $this->assertSame(5, $before['Bali']['adjustment']);

        $category->teams()->whereKey($this->teams['Bali'])->update(['status' => 'disqualified']);

        $after = $this->rows($category);
        $this->assertArrayNotHasKey('Bali', $after);
        // Arema's win goes with it — rows() needs both sides of a fixture
        // present, which is pre-existing behaviour and not this feature's to
        // change. What matters here is that the 5 did not survive onto the one
        // row still standing.
        $this->assertSame(0, $after['Arema']['adjustment']);
        $this->assertSame($after['Arema']['match_points'], $after['Arema']['points']);
    }
}
