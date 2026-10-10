<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventViewDaily;
use App\Models\Organization;
use App\Models\User;
use App\Services\EventViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

class AdminViewStatsTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function org(string $name): Organization
    {
        return Organization::create([
            'name' => $name, 'slug' => 'org-'.uniqid(), 'owner_id' => User::factory()->create()->id,
        ]);
    }

    private function event(Organization $org, string $name = 'Cup'): Event
    {
        return $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => $name, 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'tournament_format' => 'league', 'status' => 'open',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-02',
        ]);
    }

    private function seedViews(Event $event, int $views, int $uniques): void
    {
        EventViewDaily::create([
            'event_id' => $event->id,
            'organization_id' => $event->organization_id,
            'viewed_on' => app(EventViewService::class)->today()->toDateString(),
            'views' => $views,
            'unique_visitors' => $uniques,
        ]);
    }

    public function test_super_admin_sees_platform_wide_totals(): void
    {
        $this->seedViews($this->event($this->org('Alpha')), 100, 70);
        $this->seedViews($this->event($this->org('Beta')), 40, 30);

        $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/view-stats')
            ->assertOk()
            ->assertJsonPath('data.totals.views', 140)
            ->assertJsonPath('data.totals.unique_visitors', 100)
            ->assertJsonCount(30, 'data.trend');
    }

    public function test_a_regular_user_cannot_read_platform_traffic(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson('/api/v1/admin/view-stats')
            ->assertStatus(403);
    }

    public function test_breakdown_by_organization_ranks_and_counts_events(): void
    {
        $alpha = $this->org('Alpha');
        $this->seedViews($this->event($alpha, 'A1'), 60, 40);
        $this->seedViews($this->event($alpha, 'A2'), 30, 20);
        $this->seedViews($this->event($this->org('Beta')), 10, 8);

        $items = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/view-stats/organizations')
            ->assertOk()
            ->assertJsonPath('data.has_more', false)
            ->json('data.items');

        $this->assertSame('Alpha', $items[0]['name']);
        $this->assertSame(90, $items[0]['views']);
        $this->assertSame(2, $items[0]['events_count']);
        $this->assertSame('Beta', $items[1]['name']);
    }

    public function test_breakdown_by_event_can_be_narrowed_to_one_organization(): void
    {
        $alpha = $this->org('Alpha');
        $this->seedViews($this->event($alpha, 'A1'), 60, 40);
        $this->seedViews($this->event($this->org('Beta'), 'B1'), 500, 400);

        $admin = $this->superAdmin();

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/view-stats/events')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');

        $narrowed = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/view-stats/events?organization_id='.$alpha->id)
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $narrowed);
        $this->assertSame('A1', $narrowed[0]['name']);
        $this->assertSame('Alpha', $narrowed[0]['organization_name']);
    }

    /**
     * Comparing both halves of the cap in one test: asserting only that a
     * limited page returns `limit` rows passes just as happily when `has_more`
     * is hardcoded false, which is the bug — a list cut in silence reads as
     * traffic that was never recorded.
     */
    public function test_a_truncated_breakdown_says_there_is_more(): void
    {
        $org = $this->org('Alpha');

        foreach ([30, 20, 10] as $i => $views) {
            $this->seedViews($this->event($org, 'E'.$i), $views, $views);
        }

        $admin = $this->superAdmin();

        $cut = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/view-stats/events?limit=2')
            ->assertOk()
            ->assertJsonPath('data.has_more', true)
            ->json('data.items');

        $this->assertCount(2, $cut);
        $this->assertSame(['E0', 'E1'], array_column($cut, 'name'));

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/view-stats/events?limit=3')
            ->assertOk()
            ->assertJsonPath('data.has_more', false)
            ->assertJsonCount(3, 'data.items');
    }

    /**
     * The quiet event this feature exists for: it sits at the bottom of the
     * traffic ordering, so it is only ever reachable by name.
     */
    public function test_event_breakdown_can_be_searched_by_event_or_organizer_name(): void
    {
        $busy = $this->org('Busy Org');
        $this->seedViews($this->event($busy, 'Futsal Competition'), 5000, 3000);

        $quiet = $this->org('DIRAY');
        $this->seedViews($this->event($quiet, 'DIRAY CUP 10'), 18, 10);

        $admin = $this->superAdmin();

        // Lower-cased on purpose: plain LIKE is case-sensitive on Postgres, so
        // a search written without Search::anyColumn passes on sqlite only.
        $byEvent = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/view-stats/events?limit=1&q=diray+cup')
            ->assertOk()
            ->assertJsonPath('data.has_more', false)
            ->json('data.items');

        $this->assertCount(1, $byEvent);
        $this->assertSame('DIRAY CUP 10', $byEvent[0]['name']);

        $byOrganizer = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/view-stats/events?limit=1&q=diray')
            ->assertOk()
            ->json('data.items');

        $this->assertSame('DIRAY CUP 10', $byOrganizer[0]['name']);
    }

    public function test_organization_breakdown_can_be_searched_by_name(): void
    {
        $this->seedViews($this->event($this->org('Busy Org'), 'Big'), 5000, 3000);
        $this->seedViews($this->event($this->org('DIRAY'), 'DIRAY CUP 10'), 18, 10);

        $items = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/view-stats/organizations?limit=1&q=diray')
            ->assertOk()
            ->assertJsonPath('data.has_more', false)
            ->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame('DIRAY', $items[0]['name']);
        $this->assertSame(18, $items[0]['views']);
    }

    /**
     * A `%` typed into the box is text, not a pattern. Without ESCAPE it
     * returns every row, which reads exactly like a filter that does nothing.
     */
    public function test_a_wildcard_in_the_search_term_is_treated_as_literal_text(): void
    {
        $this->seedViews($this->event($this->org('Alpha'), 'Alpha Cup'), 10, 5);

        $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/view-stats/events?q=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }
}
