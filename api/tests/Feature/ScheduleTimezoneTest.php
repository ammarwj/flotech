<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kickoff times are stored as UTC instants, but the organizer types a wall clock
 * that means the *venue's* zone. The app runs in UTC, so parsing "15:00" without
 * a zone silently wrote 15:00Z — a 15:00 WIB kickoff showed up at 22:00.
 *
 * Every assertion here compares two events that differ only in timezone. A test
 * that checks one event in isolation would still pass if the conversion were
 * dropped entirely, so the comparison is the point.
 */
class ScheduleTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function categoryIn(string $timezone): EventCategory
    {
        $owner = User::factory()->create();
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);
        $org = Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);

        /** @var Event $event */
        $event = $org->events()->create([
            'name' => 'Cup',
            'slug' => 'cup-'.uniqid(),
            'sport_type' => 'mini_soccer',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
            'timezone' => $timezone,
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

    /** The stored UTC time of the earliest fixture, "H:i". */
    private function firstKickoffUtc(EventCategory $category): string
    {
        return $category->matches()
            ->orderBy('scheduled_at')
            ->first()
            ->scheduled_at
            ->utc()
            ->format('H:i');
    }

    public function test_daily_start_is_read_in_the_events_timezone(): void
    {
        $service = app(ScheduleService::class);
        $opts = ['start_date' => '2026-08-01', 'daily_start' => '15:00', 'daily_end' => '21:00'];

        $jakarta = $this->categoryIn('Asia/Jakarta');
        $service->generateRoundRobin($jakarta);
        $service->applySchedule($jakarta, $opts);

        $jayapura = $this->categoryIn('Asia/Jayapura');
        $service->generateRoundRobin($jayapura);
        $service->applySchedule($jayapura, $opts);

        // Same "15:00" in, two different instants out — one per venue clock.
        $this->assertSame('08:00', $this->firstKickoffUtc($jakarta), '15:00 WIB is 08:00Z');
        $this->assertSame('06:00', $this->firstKickoffUtc($jayapura), '15:00 WIT is 06:00Z');
    }

    public function test_kickoff_reads_back_as_the_wall_clock_the_organizer_typed(): void
    {
        $service = app(ScheduleService::class);
        $opts = ['start_date' => '2026-08-01', 'daily_start' => '19:30', 'daily_end' => '22:00'];

        foreach (['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'] as $zone) {
            $category = $this->categoryIn($zone);
            $service->generateRoundRobin($category);
            $service->applySchedule($category, $opts);

            $local = $category->matches()
                ->orderBy('scheduled_at')
                ->first()
                ->scheduled_at
                ->setTimezone($zone);

            $this->assertSame('19:30', $local->format('H:i'), "19:30 must stay 19:30 in {$zone}");
            // An evening kickoff is the most likely to slip a day when converted.
            $this->assertSame('2026-08-01', $local->format('Y-m-d'), "kickoff must stay on the start date in {$zone}");
        }
    }

    public function test_schedule_stays_within_the_event_dates_in_a_far_east_timezone(): void
    {
        $service = app(ScheduleService::class);

        $category = $this->categoryIn('Asia/Jayapura');
        $service->generateRoundRobin($category);
        $service->applySchedule($category, [
            'start_date' => '2026-08-01',
            'daily_start' => '15:00',
            'daily_end' => '21:00',
        ]);

        foreach ($category->matches()->get() as $match) {
            $day = $match->scheduled_at->setTimezone('Asia/Jayapura')->format('Y-m-d');
            $this->assertGreaterThanOrEqual('2026-08-01', $day);
            $this->assertLessThanOrEqual('2026-08-30', $day);
        }
    }
}
