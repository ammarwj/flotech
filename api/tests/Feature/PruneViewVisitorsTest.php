<?php

namespace Tests\Feature;

use App\Models\EventViewDaily;
use App\Models\Organization;
use App\Models\User;
use App\Services\EventViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneViewVisitorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pruning_drops_old_visitor_rows_but_never_the_daily_totals(): void
    {
        $views = app(EventViewService::class);

        $org = Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => User::factory()->create()->id,
        ]);
        $event = $org->events()->create([
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'tournament_format' => 'league', 'status' => 'open',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-02',
        ]);

        $longAgo = $views->today()->subDays(120);
        $recent = $views->today()->subDays(2);

        foreach ([$longAgo, $recent] as $day) {
            DB::table('event_view_visitors')->insert([
                'id' => (string) Str::uuid(),
                'event_id' => $event->id,
                'viewed_on' => $day->toDateString(),
                'visitor_hash' => hash('sha256', $day->toDateString()),
                'created_at' => $day,
            ]);

            EventViewDaily::create([
                'event_id' => $event->id,
                'organization_id' => $org->id,
                'viewed_on' => $day->toDateString(),
                'views' => 10,
                'unique_visitors' => 1,
            ]);
        }

        $this->artisan('views:prune')->assertSuccessful();

        // The ledger loses the expired day...
        $this->assertDatabaseCount('event_view_visitors', 1);
        $this->assertDatabaseMissing('event_view_visitors', ['viewed_on' => $longAgo->toDateString()]);

        // ...but the statistics it produced are kept forever. This is the whole
        // point of splitting the two tables.
        $this->assertDatabaseCount('event_view_daily', 2);
        $this->assertSame(20, (int) DB::table('event_view_daily')->sum('views'));
    }
}
