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
 * When an event defines named courts, the slot allocator labels each lane with a
 * court name and derives the lane count from the list — replacing the bare
 * `venues` integer and its generic "Lapangan N".
 *
 * Every assertion compares a named-courts run against the count-only fallback on
 * the same fixtures: a test that only checked the names in isolation would still
 * pass if the fallback had been broken, and vice versa.
 */
class ScheduleCourtsTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>|null  $courts */
    private function categoryWithCourts(?array $courts): EventCategory
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
            'sport_type' => 'badminton',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
            'timezone' => 'Asia/Jakarta',
            'courts' => $courts,
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

    /** @return list<string|null> every fixture's stored venue. */
    private function venuesOf(EventCategory $category): array
    {
        return $category->matches()->orderBy('round')->orderBy('id')->pluck('venue')->all();
    }

    public function test_named_courts_label_the_lanes_and_set_their_count(): void
    {
        $service = app(ScheduleService::class);
        $opts = ['start_date' => '2026-08-01', 'daily_start' => '15:00', 'daily_end' => '21:00'];

        // Named courts on the event drive the labels; the controller injects them
        // into the options, so mirror that here.
        $named = $this->categoryWithCourts(['Lapangan A', 'Lapangan B']);
        $service->generateRoundRobin($named);
        $service->applySchedule($named, $opts + ['courts' => $named->event->courts]);

        // No list → the old integer fallback and its generic labels.
        $fallback = $this->categoryWithCourts(null);
        $service->generateRoundRobin($fallback);
        $service->applySchedule($fallback, $opts + ['venues' => 2]);

        $namedVenues = $this->venuesOf($named);
        $fallbackVenues = $this->venuesOf($fallback);

        // Only the real court names appear, never the generic ones.
        $this->assertContains('Lapangan A', $namedVenues);
        $this->assertContains('Lapangan B', $namedVenues);
        $this->assertNotContains('Lapangan 1', $namedVenues);

        // The fallback proves the comparison: same lane count, generic labels.
        $this->assertContains('Lapangan 1', $fallbackVenues);
        $this->assertContains('Lapangan 2', $fallbackVenues);
        $this->assertNotContains('Lapangan A', $fallbackVenues);
    }

    public function test_a_single_court_leaves_no_generic_label(): void
    {
        $service = app(ScheduleService::class);
        $opts = ['start_date' => '2026-08-01', 'daily_start' => '15:00', 'daily_end' => '21:00'];

        // One named court still labels every tie (unlike the count fallback, which
        // leaves venue null when there is only one lane).
        $named = $this->categoryWithCourts(['Center Court']);
        $service->generateRoundRobin($named);
        $service->applySchedule($named, $opts + ['courts' => $named->event->courts]);

        $fallback = $this->categoryWithCourts(null);
        $service->generateRoundRobin($fallback);
        $service->applySchedule($fallback, $opts + ['venues' => 1]);

        $this->assertSame(['Center Court'], array_values(array_unique($this->venuesOf($named))));
        $this->assertSame([null], array_values(array_unique($this->venuesOf($fallback))));
    }
}
