<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\User;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The slot allocator prefers one round per day, but the event's date range is
 * the harder constraint: an organizer who typed a single day means a single
 * day. Rounds share a day down to what a day can actually hold, and only
 * overflow past the end date when the daily window genuinely cannot fit them.
 *
 * Every assertion here **compares** a tight range against a roomy one built
 * from the same fixtures. Asserting the tight run alone would still pass if the
 * allocator had stopped reading the range at all and simply packed everything
 * onto day one.
 */
class ScheduleDateRangeTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /** A 4-team league (3 rounds × 2 ties) over the given range. */
    private function league(string $start, string $end): EventCategory
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        /** @var Event $event */
        $event = $this->eventOn($org, null, [
            'sport_type' => 'badminton',
            'start_date' => $start,
            'end_date' => $end,
            'timezone' => 'Asia/Jakarta',
        ]);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        foreach (range(1, 4) as $i) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => 'Team '.$i,
                'status' => 'approved',
                'contact_name' => 'PIC',
                'contact_phone' => '0800',
            ]);
        }

        return $category;
    }

    /**
     * Distinct calendar days the fixtures landed on, in the venue's zone — the
     * only zone in which "how many days" is a meaningful question.
     */
    private function daysOf(EventCategory $category): array
    {
        return $category->matches()
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn ($m) => $m->scheduled_at->setTimezone($category->timezone)->toDateString())
            ->unique()
            ->values()
            ->all();
    }

    private function generate(EventCategory $category, array $opts = []): void
    {
        $service = app(ScheduleService::class);
        $service->generateRoundRobin($category);
        $service->applySchedule($category, $opts + [
            'daily_start' => '15:00',
            'daily_end' => '21:00',
            'match_minutes' => 60,
            'break_minutes' => 15,
            'venues' => 3,
        ]);
    }

    public function test_a_one_day_event_keeps_every_round_on_that_day(): void
    {
        // Same fixtures, same daily window, same lane count — only the range
        // differs. Three rounds fit inside one day's 3 courts × 4 slots.
        $oneDay = $this->league('2026-10-04', '2026-10-04');
        $this->generate($oneDay);

        $roomy = $this->league('2026-10-04', '2026-10-30');
        $this->generate($roomy);

        $this->assertSame(['2026-10-04'], $this->daysOf($oneDay));

        // The comparison: with room, the allocator still gives each round its
        // own day. Without it the assertion above would pass on an allocator
        // that had simply stopped spreading anything.
        $this->assertCount(3, $this->daysOf($roomy));
    }

    public function test_rounds_share_days_when_the_range_is_shorter_than_the_round_count(): void
    {
        // Two days, three rounds: two rounds have to share one.
        $twoDays = $this->league('2026-10-04', '2026-10-05');
        $this->generate($twoDays);

        $this->assertSame(['2026-10-04', '2026-10-05'], $this->daysOf($twoDays));
    }

    public function test_every_fixture_keeps_its_own_slot_when_rounds_share_a_day(): void
    {
        $oneDay = $this->league('2026-10-04', '2026-10-04');
        $this->generate($oneDay);

        // Six ties on three courts: no two may collide on the same court at the
        // same kickoff. A per-round slot counter would restart each round at
        // 15:00 on court 1 and stack them three deep.
        $slots = $oneDay->matches()->get()
            ->map(fn ($m) => $m->scheduled_at->toDateTimeString().'|'.$m->venue)
            ->all();

        $this->assertCount(6, $slots);
        $this->assertSame(count($slots), count(array_unique($slots)));
    }

    public function test_the_range_never_squeezes_more_into_a_day_than_it_holds(): void
    {
        // One day, but only one court and a window with room for two ties:
        // capacity, not preference, decides — the remaining four overflow past
        // the end date rather than double-booking court 1.
        $tight = $this->league('2026-10-04', '2026-10-04');
        $this->generate($tight, ['venues' => 1, 'daily_end' => '17:15']);

        $slots = $tight->matches()->get()
            ->map(fn ($m) => $m->scheduled_at->toDateTimeString().'|'.$m->venue)
            ->all();

        $this->assertSame(count($slots), count(array_unique($slots)), 'fixtures double-booked a lane');
        $this->assertGreaterThan(1, count($this->daysOf($tight)));
    }
}
