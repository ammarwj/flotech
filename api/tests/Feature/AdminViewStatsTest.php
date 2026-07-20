<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventViewDaily;
use App\Models\Organization;
use App\Models\User;
use App\Services\EventViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminViewStatsTest extends TestCase
{
    use RefreshDatabase;

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
}
