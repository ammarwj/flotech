<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The landing page's proof counters.
 *
 * Every assertion here is a *comparison*: for each counter the fixture holds a
 * row that must be counted next to one that must not, so an implementation
 * that dropped the status filter and counted everything fails. Asserting
 * "greater than zero" would pass on that implementation.
 */
class PublicStatTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The endpoint caches for ten minutes; without this the second test in
        // the run would read the first one's numbers.
        Cache::flush();
    }

    private function org(): Organization
    {
        return Organization::create([
            'name' => 'Org '.uniqid(),
            'slug' => 'org-'.uniqid(),
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    private function event(Organization $org, string $status): Event
    {
        return $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => ucfirst($status).' Cup',
            'slug' => 'cup-'.uniqid(),
            'sport_type' => 'futsal',
            'status' => $status,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-02',
        ]);
    }

    private function categoryId(Event $event): string
    {
        return $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum-'.uniqid(),
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
        ])->id;
    }

    /**
     * @return array<string, int>
     */
    private function stats(): array
    {
        return $this->getJson('/api/v1/stats')->assertOk()->json('data');
    }

    public function test_only_events_that_actually_ran_count_as_tournaments(): void
    {
        $org = $this->org();

        foreach (['draft', 'open', 'registration_closed', 'cancelled'] as $status) {
            $this->event($org, $status);
        }

        $this->event($org, 'ongoing');
        $this->event($org, 'finished');

        // Six events exist; only the two that reached the field are tournaments.
        $this->assertSame(2, $this->stats()['tournaments']);
    }

    public function test_only_accepted_teams_count(): void
    {
        $event = $this->event($this->org(), 'finished');
        $category = $this->categoryId($event);

        foreach (['pending', 'rejected', 'approved', 'disqualified', 'withdrawn'] as $status) {
            $event->teams()->create(['name' => 'Tim '.$status, 'status' => $status, 'category_id' => $category]);
        }

        // approved + disqualified + withdrawn — a team that was thrown out or
        // pulled back still took part; pending and rejected never did.
        $this->assertSame(3, $this->stats()['teams']);
    }

    public function test_only_finished_matches_count(): void
    {
        $event = $this->event($this->org(), 'finished');
        $category = $this->categoryId($event);
        $home = $event->teams()->create(['name' => 'Home', 'status' => 'approved', 'category_id' => $category]);
        $away = $event->teams()->create(['name' => 'Away', 'status' => 'approved', 'category_id' => $category]);

        foreach (['scheduled', 'ongoing', 'cancelled', 'finished'] as $status) {
            $event->matches()->create([
                'category_id' => $category,
                'home_team_id' => $home->id,
                'away_team_id' => $away->id,
                'status' => $status,
            ]);
        }

        $this->assertSame(1, $this->stats()['matches']);
    }

    public function test_tickets_count_issued_rows_not_orders(): void
    {
        $event = $this->event($this->org(), 'finished');
        $category = $event->ticketCategories()->create([
            'name' => 'Reguler', 'price' => 50000, 'is_active' => true,
        ]);

        // A pending order issues nothing, so its quantity must not show up.
        $event->ticketOrders()->create([
            'ticket_category_id' => $category->id,
            'buyer_name' => 'Belum Bayar', 'buyer_email' => 'pending@example.test',
            'quantity' => 9, 'unit_price' => 50000, 'total_price' => 450000,
            'status' => 'pending',
        ]);

        $paid = $event->ticketOrders()->create([
            'ticket_category_id' => $category->id,
            'buyer_name' => 'Lunas', 'buyer_email' => 'paid@example.test',
            'quantity' => 2, 'unit_price' => 50000, 'total_price' => 100000,
            'status' => 'paid', 'paid_at' => now(),
        ]);

        foreach (range(1, 2) as $n) {
            Ticket::create([
                'order_id' => $paid->id,
                'ticket_category_id' => $category->id,
                'event_id' => $event->id,
                'qr_code' => 'QR-'.uniqid().$n,
                'holder_name' => 'Penonton '.$n,
            ]);
        }

        $this->assertSame(2, $this->stats()['tickets']);
    }

    public function test_endpoint_is_public_and_returns_raw_numbers(): void
    {
        $stats = $this->stats();

        $this->assertSame(['tournaments', 'teams', 'tickets', 'matches'], array_keys($stats));

        // Raw integers: formatting ("38rb") belongs to the web app.
        foreach ($stats as $value) {
            $this->assertIsInt($value);
        }
    }
}
