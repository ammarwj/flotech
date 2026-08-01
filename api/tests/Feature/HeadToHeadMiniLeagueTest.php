<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\StandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Head to head once more than two teams are level.
 *
 * It is a table among the tied teams, not a comparison of two of them. Read
 * pairwise, three teams that beat each other in a circle separate perfectly —
 * and contradict, so the order becomes an artefact of how the sort walked them
 * and the criterion below head to head is never reached. That is the bug these
 * tests hold shut: a real group (KABOAX CUP 2026, grup L) ranked a team on
 * goal difference −1 above one on 0.
 *
 * So the assertions here are deliberately comparative. "The table came out in
 * this order" proves nothing on its own when the old behaviour also produced
 * *an* order; each test either runs the same fixtures under a different initial
 * ordering, or is built so that the right answer and the answer the next
 * criterion down would give are different.
 */
class HeadToHeadMiniLeagueTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function org(): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => User::factory()->create()->id, 'plan_id' => $plan->id,
        ]);
    }

    /**
     * A football league over `$names`, with `$results` as [home, hs, as, away]
     * naming teams by their key in `$names` — so a caller can rename the teams
     * without touching the fixtures, which is what the ordering test needs.
     *
     * @param  array<string, string>  $names  slot => team name
     * @param  array<int, array{0: string, 1: int, 2: int, 3: string}>  $results
     */
    private function league(Organization $org, array $names, array $results): Event
    {
        $event = $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Piala Nusantara',
            'slug' => 'piala-'.uniqid(),
            'sport_type' => 'football',
            'status' => 'ongoing',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'participant_type' => 'team',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        $ids = [];
        foreach ($names as $slot => $name) {
            $ids[$slot] = $event->teams()->create([
                'category_id' => $category->id,
                'name' => $name,
                'status' => 'approved',
            ])->id;
        }

        foreach ($results as $i => [$home, $homeScore, $awayScore, $away]) {
            $event->matches()->create([
                'category_id' => $category->id,
                'round' => 1,
                'leg' => 1,
                'order' => $i + 1,
                'home_team_id' => $ids[$home],
                'away_team_id' => $ids[$away],
                'home_score' => $homeScore,
                'away_score' => $awayScore,
                'status' => 'finished',
                'confirmed_at' => now(),
            ]);
        }

        return $event->load('categories');
    }

    /**
     * The table as slot => row, so a test reads in the same vocabulary it wrote
     * the fixtures in.
     *
     * @param  array<string, string>  $names  slot => team name
     * @return array<string, array<string, mixed>>
     */
    private function table(Event $event, array $names): array
    {
        $rows = collect(app(StandingService::class)->compute($event->categories->first()->fresh()))
            ->keyBy('team.name');

        return collect($names)->map(fn ($name) => $rows[$name])->all();
    }

    /**
     * The order slots came out in, best first.
     *
     * @param  array<string, array<string, mixed>>  $table
     * @return array<int, string>
     */
    private function order(array $table): array
    {
        return collect($table)->sortBy('rank')->keys()->all();
    }

    /**
     * KABOAX CUP 2026, grup L, exactly as it was played: a perfect circle, so
     * every team has one win, one loss and three points, and nothing about the
     * meetings between them can separate anyone.
     *
     * @return array<int, array{0: string, 1: int, 2: int, 3: string}>
     */
    private function circle(): array
    {
        return [
            ['amf', 3, 4, 'hmn'],
            ['ruang', 1, 2, 'amf'],
            ['ruang', 4, 2, 'hmn'],
        ];
    }

    public function test_a_three_way_circle_falls_through_to_goal_difference(): void
    {
        $names = ['amf' => 'AMF MARKING', 'hmn' => 'HMN', 'ruang' => 'RUANG MANU'];
        $event = $this->league($this->org(), $names, $this->circle());
        $table = $this->table($event, $names);

        // The circle leaves all three level on points and level in the table
        // they make among themselves, so goal difference — the criterion below
        // head to head — is what decides, and it has an answer for all three.
        $this->assertSame(3, $table['amf']['points']);
        $this->assertSame(3, $table['hmn']['points']);
        $this->assertSame(3, $table['ruang']['points']);
        $this->assertSame(0, $table['amf']['goal_diff']);
        $this->assertSame(-1, $table['hmn']['goal_diff']);
        $this->assertSame(1, $table['ruang']['goal_diff']);

        $this->assertSame(['ruang', 'amf', 'hmn'], $this->order($table));

        // Separated by a criterion, so nobody is owed a decider. A circle read
        // pairwise is not a deadlock either — it is worse, because it looks
        // decided while being decided by nothing.
        foreach ($table as $slot => $row) {
            $this->assertFalse($row['needs_decider'], "{$slot} ditandai butuh laga penentuan.");
        }
    }

    public function test_the_order_teams_were_entered_in_cannot_change_the_circles_result(): void
    {
        // Same three fixtures, three times, with the names shuffled between the
        // slots — the table is built with orderBy('name'), so each run hands
        // the sort a different starting order. This is the test that fails on
        // the pairwise comparator: it returned whichever team the sort happened
        // to compare first.
        $permutations = [
            ['amf' => 'Aaa', 'hmn' => 'Mmm', 'ruang' => 'Zzz'],
            ['amf' => 'Zzz', 'hmn' => 'Aaa', 'ruang' => 'Mmm'],
            ['amf' => 'Mmm', 'hmn' => 'Zzz', 'ruang' => 'Aaa'],
        ];

        foreach ($permutations as $names) {
            $event = $this->league($this->org(), $names, $this->circle());

            $this->assertSame(
                ['ruang', 'amf', 'hmn'],
                $this->order($this->table($event, $names)),
                'Urutan klasemen berubah hanya karena nama timnya berbeda: '.implode(', ', $names),
            );
        }
    }

    public function test_head_to_head_counts_only_the_matches_between_the_tied_teams(): void
    {
        // A, B and C are level on points; D won everything and sits apart, so
        // the tie is exactly {A, B, C}.
        //
        // Among themselves they circle again — level on points — and their goal
        // difference *in those three matches* reads B +2, A 0, C −2.
        //
        // Their goal difference across the whole group reads A −1, B −3, C −3,
        // because the thrashings D handed out land on it and not on the mini
        // table. So overall goal difference would say A, B, C and head to head
        // says B, A, C: whichever the table shows is the one being used.
        $names = ['a' => 'Arema', 'b' => 'Bali United', 'c' => 'Chelsea', 'd' => 'Dewa United'];

        $event = $this->league($this->org(), $names, [
            ['a', 1, 0, 'b'],
            ['b', 3, 0, 'c'],
            ['c', 2, 1, 'a'],
            ['d', 1, 0, 'a'],
            ['d', 5, 0, 'b'],
            ['d', 1, 0, 'c'],
        ]);

        $table = $this->table($event, $names);

        $this->assertSame(9, $table['d']['points']);
        foreach (['a', 'b', 'c'] as $slot) {
            $this->assertSame(3, $table[$slot]['points'], "{$slot} tidak seri poin, blok tie-nya salah.");
        }

        // The state that makes the two answers differ — asserted so a future
        // edit to the fixtures cannot quietly make this test tautological.
        $this->assertSame(-1, $table['a']['goal_diff']);
        $this->assertSame(-3, $table['b']['goal_diff']);
        $this->assertSame(-3, $table['c']['goal_diff']);

        $this->assertSame(['d', 'b', 'a', 'c'], $this->order($table));
    }

    public function test_two_teams_left_level_get_their_own_head_to_head_before_anything_below_it(): void
    {
        // A, B and C are level on points, and in the table among themselves A
        // and B are level too — same points, same goal difference, same goals
        // scored — while C drops below on goals scored.
        //
        // What separates A and B is that A beat B, which only a head to head
        // restricted to the two of them can see. Overall goal difference, the
        // criterion below, reads A −4 and B −1 and would put B first: the
        // order proves whether the tiebreakers restarted for the pair left over
        // or simply carried on down the list.
        $names = ['a' => 'Arema', 'b' => 'Bali United', 'c' => 'Chelsea', 'd' => 'Dewa United'];

        $event = $this->league($this->org(), $names, [
            ['a', 2, 1, 'b'],
            ['a', 0, 1, 'c'],
            ['b', 1, 0, 'c'],
            ['d', 4, 0, 'a'],
            ['d', 1, 0, 'b'],
            ['d', 1, 0, 'c'],
        ]);

        $table = $this->table($event, $names);

        foreach (['a', 'b', 'c'] as $slot) {
            $this->assertSame(3, $table[$slot]['points'], "{$slot} tidak seri poin, blok tie-nya salah.");
        }

        $this->assertSame(-4, $table['a']['goal_diff']);
        $this->assertSame(-1, $table['b']['goal_diff']);

        $this->assertSame(['d', 'a', 'b', 'c'], $this->order($table));
    }

    public function test_two_teams_are_still_separated_by_the_match_they_played(): void
    {
        // The plain case the pairwise comparator already handled, kept so the
        // move to a table cannot regress it. A and B are the only two level on
        // points, and A beat B.
        $names = ['a' => 'Arema', 'b' => 'Bali United', 'c' => 'Chelsea', 'd' => 'Dewa United'];

        $event = $this->league($this->org(), $names, [
            ['a', 1, 0, 'b'],
            ['b', 4, 0, 'c'],
            ['d', 2, 0, 'a'],
            ['d', 2, 0, 'c'],
        ]);

        $table = $this->table($event, $names);

        // B's rout of C gives it the better goal difference, so the head to
        // head is the only thing that can put A above it.
        $this->assertSame(3, $table['a']['points']);
        $this->assertSame(3, $table['b']['points']);
        $this->assertSame(-1, $table['a']['goal_diff']);
        $this->assertSame(3, $table['b']['goal_diff']);

        $this->assertSame(['d', 'a', 'b', 'c'], $this->order($table));
    }

    public function test_three_teams_nothing_can_separate_are_all_owed_a_decider(): void
    {
        // Every match drawn: same points, same goal difference, same goals
        // scored, and a head to head among all three that says nothing. Only
        // the lot put them in an order, and all three are told so — the pairwise
        // comparator flagged nobody here, because it only ever looked at
        // neighbours.
        $names = ['a' => 'Arema', 'b' => 'Bali United', 'c' => 'Chelsea'];

        $event = $this->league($this->org(), $names, [
            ['a', 1, 1, 'b'],
            ['b', 1, 1, 'c'],
            ['c', 1, 1, 'a'],
        ]);

        $table = $this->table($event, $names);

        foreach ($table as $slot => $row) {
            $this->assertSame(2, $row['points']);
            $this->assertTrue($row['needs_decider'], "{$slot} tidak ditandai butuh laga penentuan.");
        }

        // Still a total order, so the page has something to render.
        $this->assertSame([1, 2, 3], collect($table)->pluck('rank')->sort()->values()->all());
    }

    public function test_one_service_asked_twice_reads_the_results_saved_in_between(): void
    {
        // The fixtures behind a table are memoized for the length of one
        // computation, because a hybrid category reads them once per group and
        // again per tied block. The same service can outlive that, though: the
        // knockout plan is asked for both before a ball is kicked and after the
        // groups are done. A memo scoped to the object rather than to the
        // computation answers the second question with the first one's
        // fixtures, and every team stays on nought points forever.
        //
        // Two calls on *one* instance is the whole test — a fresh service each
        // time would pass no matter what.
        $names = ['a' => 'Arema', 'b' => 'Bali United'];
        $event = $this->league($this->org(), $names, []);
        $category = $event->categories->first();

        $service = app(StandingService::class);

        foreach ($service->compute($category->fresh()) as $row) {
            $this->assertSame(0, $row['points']);
            $this->assertSame(0, $row['played']);
        }

        $event->matches()->create([
            'category_id' => $category->id,
            'round' => 1,
            'leg' => 1,
            'order' => 1,
            'home_team_id' => $event->teams()->where('name', 'Arema')->value('id'),
            'away_team_id' => $event->teams()->where('name', 'Bali United')->value('id'),
            'home_score' => 2,
            'away_score' => 0,
            'status' => 'finished',
            'confirmed_at' => now(),
        ]);

        $table = collect($service->compute($category->fresh()))->keyBy('team.name');

        $this->assertSame(3, $table['Arema']['points'], 'klasemen masih memakai jadwal lama yang belum ada hasilnya');
        $this->assertSame(1, $table['Arema']['played']);
        $this->assertSame(2, $table['Arema']['goals_for']);
        $this->assertSame(0, $table['Bali United']['points']);
    }
}
