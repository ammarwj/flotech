<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

class AdminStatsTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function org(): Organization
    {
        return Organization::create([
            'name' => 'Org '.uniqid(), 'slug' => 'org-'.uniqid(),
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    private function event(Organization $org, string $status): Event
    {
        return $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => ucfirst($status).' Cup', 'slug' => 'cup-'.uniqid(),
            'sport_type' => 'futsal', 'status' => $status,
            'start_date' => '2026-08-01', 'end_date' => '2026-08-02',
        ]);
    }

    /**
     * Two events share a status so `total` can't be mistaken for "number of
     * statuses in use", and only one is `ongoing` so the two numbers the card
     * shows are provably different figures.
     */
    private function seedSpread(): Organization
    {
        $org = $this->org();

        foreach (['draft', 'open', 'open', 'ongoing', 'finished', 'cancelled'] as $status) {
            $this->event($org, $status);
        }

        return $org;
    }

    public function test_active_counts_everything_that_is_not_finished_or_cancelled(): void
    {
        $this->seedSpread();

        $events = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/stats')
            ->assertOk()
            ->json('data.events');

        $this->assertSame(6, $events['total']);
        // draft + open + open + ongoing — the two terminal statuses drop out,
        // and a draft still counts, exactly like the plan quota counts it.
        $this->assertSame(4, $events['active']);
        // The comparison is the point: an implementation that returned `total`
        // for `active`, or conflated "aktif" with "sedang berjalan", fails here.
        $this->assertLessThan($events['active'], $events['ongoing']);
        $this->assertSame(1, $events['ongoing']);
    }

    public function test_breakdown_lists_every_known_status_including_the_empty_ones(): void
    {
        $this->seedSpread();

        $events = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/stats')
            ->assertOk()
            ->json('data.events');

        $this->assertSame(array_keys(Event::TRANSITIONS), array_keys($events['by_status']));
        $this->assertSame(2, $events['by_status']['open']);
        // No event holds this status, and the row must still be there — a
        // missing key reads as "no such status" rather than "none yet".
        $this->assertSame(0, $events['by_status']['registration_closed']);
        $this->assertSame($events['total'], array_sum($events['by_status']));
    }

    public function test_counts_span_every_organization(): void
    {
        $this->event($this->org(), 'open');
        $this->event($this->org(), 'finished');

        $events = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/stats')
            ->assertOk()
            ->json('data.events');

        $this->assertSame(2, $events['total']);
        $this->assertSame(1, $events['active']);
    }

    public function test_a_regular_user_cannot_read_platform_counts(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson('/api/v1/admin/stats')
            ->assertStatus(403);
    }
}
