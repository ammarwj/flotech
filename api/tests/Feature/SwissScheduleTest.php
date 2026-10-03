<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Services\ScheduleService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * A Swiss category is built one round at a time, days apart, and the rounds
 * behind the one being generated have already been played.
 *
 * That is the whole subject here: every assertion *compares* the fixtures of an
 * earlier round before and after a later round is generated and timed. Checking
 * only that the new round appeared would pass just as happily while round 1 was
 * quietly moved to another day and another court.
 */
class SwissScheduleTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /** @var array<string, string> name => team id */
    private array $teams = [];

    private function category(array $event = []): EventCategory
    {
        $org = $this->orgFor(User::factory()->create());
        $ev = $this->eventOn($org, null, ['status' => 'ongoing', ...$event]);

        $category = $ev->categories()->create([
            'name' => 'Utama',
            'slug' => 'utama',
            'participant_type' => 'team',
            'tournament_format' => 'swiss',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        $this->teams = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $name) {
            $this->teams[$name] = $ev->teams()->create([
                'category_id' => $category->id,
                'name' => $name,
                'status' => 'approved',
            ])->id;
        }

        return $category;
    }

    /** Standings order for round 1: the entry list as it stands. */
    private function order(EventCategory $category): array
    {
        return array_values($this->teams);
    }

    /** Settle every fixture of a round, so the next one can be built on it. */
    private function finish(EventCategory $category, int $round): void
    {
        $category->matches()->where('round', $round)->get()->each(function ($match) {
            if ($match->away_team_id === null) {
                return; // a bye is already finished
            }

            $match->update([
                'home_score' => 2,
                'away_score' => 1,
                'status' => 'finished',
                'confirmed_at' => now(),
            ]);
        });
    }

    /** round => [order => "scheduled_at|venue"], as stored. */
    private function timings(EventCategory $category): array
    {
        $out = [];

        foreach ($category->matches()->orderBy('round')->orderBy('order')->get() as $match) {
            $out[$match->round][$match->order] = $match->scheduled_at->toIso8601String().'|'.$match->venue;
        }

        return $out;
    }

    /** Generate round $n and time it, the way the controller will. */
    private function round(EventCategory $category, int $n, array $opts = []): array
    {
        $service = app(ScheduleService::class);
        $result = $service->generateSwissRound($category, $n, $this->order($category));
        $service->applySchedule($category->fresh(), $opts, 'swiss', [], $n);

        return $result;
    }

    public function test_generating_a_later_round_leaves_the_earlier_rounds_where_they_were(): void
    {
        $category = $this->category();

        $this->round($category, 1);
        $this->finish($category, 1);
        $this->round($category, 2);
        $this->finish($category, 2);

        $before = $this->timings($category);

        $this->round($category, 3);

        $after = $this->timings($category);

        // Rounds 1 and 2 are untouched — every kickoff and every court. This is
        // the comparison the $round parameter exists for: a `stage = 'swiss'`
        // filter alone re-times fixtures that have already been played.
        $this->assertSame($before[1], $after[1]);
        $this->assertSame($before[2], $after[2]);

        // And round 3 really did arrive, after both of them.
        $this->assertCount(4, $after[3]);
        $this->assertGreaterThan(
            (string) $category->matches()->where('round', 2)->max('scheduled_at'),
            (string) $category->matches()->where('round', 3)->max('scheduled_at'),
        );
    }

    public function test_a_round_lands_the_day_after_the_last_day_already_scheduled(): void
    {
        // A long window: each round gets its own day.
        $roomy = $this->category(['start_date' => '2026-08-01', 'end_date' => '2026-08-30']);

        // 60-minute fixtures so all four of a round fit in one day — otherwise
        // the round spans two and "the day after" is the day after the second.
        $opts = ['match_minutes' => 60];

        $this->round($roomy, 1, $opts);
        $this->finish($roomy, 1);
        $this->round($roomy, 2, $opts);

        // ->min() is a SQL aggregate, so it answers with a string: the column
        // cast never runs and the zone has to be applied by hand.
        $firstKickoff = fn (EventCategory $c, int $r) => \Illuminate\Support\Carbon::parse(
            $c->matches()->where('round', $r)->min('scheduled_at'), 'UTC',
        )->setTimezone($c->timezone);

        $day = fn (EventCategory $c, int $r) => $firstKickoff($c, $r)->toDateString();

        $this->assertSame('2026-08-01', $day($roomy, 1));
        $this->assertSame('2026-08-02', $day($roomy, 2));

        // A one-day event: the range the organizer typed wins, so round 2 shares
        // the day rather than running past the end date. Compared with the above
        // because "round 2 is on the 2nd" proves nothing about the guard — it is
        // what happens when there is no room that the guard decides.
        $tight = $this->category(['start_date' => '2026-08-01', 'end_date' => '2026-08-01']);

        $this->round($tight, 1, $opts);
        $this->finish($tight, 1);
        $this->round($tight, 2, $opts);

        $this->assertSame('2026-08-01', $day($tight, 1));
        $this->assertSame('2026-08-01', $day($tight, 2));
        // And it is still timed, not left on the placeholder midnight.
        $this->assertNotSame('00:00', $firstKickoff($tight, 2)->format('H:i'));
    }

    public function test_the_second_round_never_repeats_a_first_round_pairing(): void
    {
        $category = $this->category();

        $this->round($category, 1);
        $this->finish($category, 1);
        $this->round($category, 2);

        $keys = fn (int $round) => $category->matches()
            ->where('round', $round)
            ->whereNotNull('away_team_id')
            ->get()
            ->map(fn ($m) => \App\Support\SwissPairing::pairKey($m->home_team_id, $m->away_team_id))
            ->sort()
            ->values()
            ->all();

        // Four fixtures each way, and no pair in common — compared as sets,
        // because a round that silently produced three fixtures would also have
        // "no repeats".
        $this->assertCount(4, $keys(1));
        $this->assertCount(4, $keys(2));
        $this->assertSame([], array_intersect($keys(1), $keys(2)));
    }

    public function test_an_odd_field_rests_a_different_entrant_each_round(): void
    {
        $category = $this->category();
        // Nine entrants: one has to sit out every round.
        $extra = $category->event->teams()->create([
            'category_id' => $category->id, 'name' => 'I', 'status' => 'approved',
        ]);
        $this->teams['I'] = $extra->id;

        $rested = [];

        for ($round = 1; $round <= 3; $round++) {
            $result = $this->round($category, $round);
            $rested[] = $result['bye'];
            $this->finish($category, $round);
        }

        // Three different entrants, not the same one three times — which is what
        // a bye counter that is never read looks like.
        $this->assertCount(3, array_unique($rested));
        $this->assertNotContains(null, $rested);
    }

    public function test_generating_a_round_does_not_delete_the_rounds_behind_it(): void
    {
        $category = $this->category();

        $this->round($category, 1);
        $this->finish($category, 1);

        $firstRoundIds = $category->matches()->where('round', 1)->pluck('id')->sort()->values()->all();

        $this->round($category, 2);

        // The same rows, not merely the same count: every other generator in
        // ScheduleService opens with ->delete(), and a Swiss round that copied
        // that would wipe results the organizer has already confirmed.
        $this->assertSame(
            $firstRoundIds,
            $category->matches()->where('round', 1)->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame(8, $category->matches()->count());
    }
}
