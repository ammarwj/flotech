<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventViewDaily;
use App\Models\Organization;
use App\Models\User;
use App\Services\EventViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventViewStatsTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id,
        ]);
    }

    private function event(Organization $org, string $name = 'Cup'): Event
    {
        return $org->events()->create([
            'name' => $name, 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'tournament_format' => 'league', 'status' => 'open',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-02',
        ]);
    }

    /** Seed traffic $daysAgo days back, bypassing the beacon. */
    private function seedViews(Event $event, int $views, int $uniques, int $daysAgo = 0): void
    {
        EventViewDaily::create([
            'event_id' => $event->id,
            'organization_id' => $event->organization_id,
            'viewed_on' => app(EventViewService::class)->today()->subDays($daysAgo)->toDateString(),
            'views' => $views,
            'unique_visitors' => $uniques,
        ]);
    }

    public function test_an_operator_member_can_read_event_traffic(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $this->seedViews($event, 40, 25);

        // Aggregates carry no personal data, so `tenant` is enough — the same
        // call the ticket report makes.
        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $this->actingAs($operator, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/view-stats")
            ->assertOk()
            ->assertJsonPath('data.totals.views', 40)
            ->assertJsonPath('data.totals.unique_visitors', 25);
    }

    public function test_an_outsider_cannot_read_event_traffic(): void
    {
        $org = $this->org(User::factory()->create());
        $event = $this->event($org);

        $this->actingAs(User::factory()->create(), 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/view-stats")
            ->assertStatus(403);
    }

    public function test_an_event_from_another_org_is_not_reachable_through_this_org(): void
    {
        $owner = User::factory()->create();
        $mine = $this->org($owner);
        $theirs = $this->org(User::factory()->create());
        $theirEvent = $this->event($theirs);

        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$mine->id}/events/{$theirEvent->id}/view-stats")
            ->assertStatus(404);
    }

    public function test_the_trend_is_thirty_consecutive_days_with_quiet_days_zero_filled(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $this->seedViews($event, 10, 8, daysAgo: 0);
        $this->seedViews($event, 5, 4, daysAgo: 3);

        $trend = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/view-stats")
            ->assertOk()
            ->json('data.trend');

        // Days with no traffic have no row. If they were dropped instead of
        // zero-filled the chart would squash its time axis and draw a trend
        // that never happened.
        $this->assertCount(30, $trend);
        $this->assertSame(10, $trend[29]['views']);
        $this->assertSame(5, $trend[26]['views']);
        $this->assertSame(0, $trend[27]['views']);
        $this->assertSame(0, $trend[28]['views']);

        $dates = array_column($trend, 'date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
    }

    public function test_org_summary_totals_every_event_and_ranks_them(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $quiet = $this->event($org, 'Quiet Cup');
        $busy = $this->event($org, 'Busy Cup');

        $this->seedViews($quiet, 10, 7);
        $this->seedViews($busy, 90, 60);

        $data = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/view-stats")
            ->assertOk()
            ->json('data');

        $this->assertSame(100, $data['totals']['views']);
        $this->assertSame(67, $data['totals']['unique_visitors']);
        $this->assertSame('Busy Cup', $data['events'][0]['name']);
        $this->assertSame('Quiet Cup', $data['events'][1]['name']);
    }

    public function test_an_event_with_no_traffic_still_appears_with_zeroes(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $this->event($org, 'Untouched Cup');

        $events = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/view-stats")
            ->assertOk()
            ->json('data.events');

        $this->assertCount(1, $events);
        $this->assertSame(0, $events[0]['views']);
    }

    public function test_org_summary_ignores_other_orgs_traffic(): void
    {
        $owner = User::factory()->create();
        $mine = $this->org($owner);
        $myEvent = $this->event($mine);
        $this->seedViews($myEvent, 10, 7);

        $theirs = $this->org(User::factory()->create());
        $this->seedViews($this->event($theirs), 999, 999);

        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$mine->id}/view-stats")
            ->assertOk()
            ->assertJsonPath('data.totals.views', 10)
            ->assertJsonCount(1, 'data.events');
    }
}
