<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventViewDaily;
use App\Models\LandingStatSetting;
use App\Models\Organization;
use App\Models\User;
use App\Services\EventViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The super-admin catalog behind the landing "Proof" strip.
 *
 * Code holds the catalog and the defaults, `landing_stat_settings` holds
 * overrides only, so most assertions here are *comparisons*: a metric that must
 * show next to one that must not, an inherited label next to a custom one, a row
 * that must be written next to one that must not. Asserting either half alone
 * passes on an implementation that does the wrong thing to the other.
 */
class LandingStatSettingTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function org(string $name = 'Alpha'): Organization
    {
        return Organization::create([
            'name' => $name, 'slug' => 'org-'.uniqid(), 'owner_id' => User::factory()->create()->id,
        ]);
    }

    private function event(?Organization $org = null): Event
    {
        return ($org ?? $this->org())->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'status' => 'open', 'start_date' => '2026-08-01', 'end_date' => '2026-08-02',
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

    /** @param  list<array<string, mixed>>  $metrics */
    private function save(array $metrics): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->superAdmin(), 'api')
            ->putJson('/api/v1/admin/landing-stats', ['metrics' => $metrics]);
    }

    /** @return array<string, int> */
    private function publicStats(): array
    {
        return collect($this->getJson('/api/v1/stats')->assertOk()->json('data'))
            ->pluck('value', 'key')
            ->all();
    }

    public function test_fresh_database_ships_visitors_on_and_tickets_off(): void
    {
        $stats = $this->publicStats();

        // Both halves of the swap in one response. Asserting only that
        // `page_views` is there passes on a strip that also still shows tickets,
        // and vice versa.
        $this->assertArrayHasKey('page_views', $stats);
        $this->assertArrayNotHasKey('tickets', $stats);

        $this->assertDatabaseCount('landing_stat_settings', 0);
    }

    public function test_the_two_traffic_metrics_read_different_columns(): void
    {
        $this->seedViews($this->event(), 100, 70);
        $this->seedViews($this->event($this->org('Beta')), 40, 30);

        $this->save([
            ['metric_key' => 'visitors', 'is_active' => true],
        ])->assertOk();

        $stats = $this->publicStats();

        // 140 vs 100: a resolver pointed at the wrong column would pass whichever
        // assertion was written alone.
        $this->assertSame(140, $stats['page_views']);
        $this->assertSame(100, $stats['visitors']);
    }

    public function test_both_traffic_metrics_still_cost_one_query(): void
    {
        $this->seedViews($this->event(), 100, 70);

        $oneMetric = $this->countViewQueries();

        $this->save([['metric_key' => 'visitors', 'is_active' => true]])->assertOk();

        $twoMetrics = $this->countViewQueries();

        // Equal AND one. "It is 1" alone would pass if `visitors` were broken into
        // zero queries; "they are equal" alone would pass at two apiece.
        $this->assertSame(1, $oneMetric);
        $this->assertSame($oneMetric, $twoMetrics);
    }

    /**
     * Queries touching `event_view_daily` for one cold public read.
     *
     * A query log rather than an assertion on the number — the aggregate has no
     * WHERE clause, so a second reader of it is a second full scan, and that is
     * the thing being guarded, not the total query count of the endpoint.
     */
    private function countViewQueries(): int
    {
        \Illuminate\Support\Facades\Cache::forget(\App\Services\LandingStatService::CACHE_KEY);

        $count = 0;
        DB::listen(function ($query) use (&$count) {
            if (str_contains($query->sql, 'event_view_daily')) {
                $count++;
            }
        });

        $this->getJson('/api/v1/stats')->assertOk();

        DB::flushQueryLog();

        return $count;
    }

    public function test_blank_label_inherits_the_catalog_beside_a_custom_one(): void
    {
        $this->save([
            ['metric_key' => 'tournaments', 'label' => 'Event sukses'],
            ['metric_key' => 'teams', 'label' => null],
        ])->assertOk();

        $labels = collect($this->getJson('/api/v1/stats')->json('data'))->pluck('label', 'key');

        $this->assertSame('Event sukses', $labels['tournaments']);
        $this->assertSame('Tim terdaftar', $labels['teams']);
    }

    public function test_switching_a_metric_off_hides_it_publicly_but_not_from_admin(): void
    {
        $this->save([['metric_key' => 'tournaments', 'is_active' => false]])->assertOk();

        // Three public vs six in the catalog, compared in one test: the admin page
        // has to keep offering what the strip no longer shows.
        $this->getJson('/api/v1/stats')->assertOk()->assertJsonCount(3, 'data');

        $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/landing-stats')
            ->assertOk()
            ->assertJsonCount(6, 'data');
    }

    public function test_sort_order_decides_the_strip_order(): void
    {
        $this->getJson('/api/v1/stats')
            ->assertJsonPath('data.0.key', 'tournaments')
            ->assertJsonPath('data.3.key', 'matches');

        $this->save([
            ['metric_key' => 'tournaments', 'sort_order' => 90],
            ['metric_key' => 'matches', 'sort_order' => 5],
        ])->assertOk();

        // Both ends, not just the first: a sort that only moved the winner to the
        // front would pass on `data.0` alone.
        $this->getJson('/api/v1/stats')
            ->assertJsonPath('data.0.key', 'matches')
            ->assertJsonPath('data.3.key', 'tournaments');
    }

    public function test_an_unknown_key_rejects_the_whole_payload(): void
    {
        $this->save([['metric_key' => 'tickets', 'is_active' => true]])->assertOk();

        $this->save([
            ['metric_key' => 'tickets', 'is_active' => false],
            ['metric_key' => 'page_view', 'is_active' => false],
        ])->assertStatus(422)->assertJsonValidationErrors('metrics.1.metric_key');

        // The legal half must not have landed. Asserting the 422 alone passes on
        // an implementation that wrote the first row before validating the second.
        $this->assertTrue(
            LandingStatSetting::query()->where('metric_key', 'tickets')->value('is_active')
        );
    }

    public function test_the_error_names_the_nearest_catalog_key(): void
    {
        $this->save([['metric_key' => 'page_view', 'is_active' => true]])
            ->assertStatus(422)
            ->assertJsonFragment([
                'Metrik "page_view" tidak ada di katalog counter landing. Maksud Anda "page_views"?',
            ]);
    }

    public function test_two_rows_for_one_metric_are_rejected(): void
    {
        $this->save([
            ['metric_key' => 'visitors', 'is_active' => true],
            ['metric_key' => 'visitors', 'is_active' => false],
        ])->assertStatus(422)->assertJsonValidationErrors('metrics.1.metric_key');
    }

    public function test_saving_flushes_the_public_cache(): void
    {
        // Warm it deliberately: no Cache::flush() in this test, because the warm
        // read is the point — put() has to invalidate it.
        $this->getJson('/api/v1/stats')->assertOk();

        $this->save([['metric_key' => 'teams', 'label' => 'Peserta']])->assertOk();

        $this->assertSame(
            'Peserta',
            collect($this->getJson('/api/v1/stats')->json('data'))->firstWhere('key', 'teams')['label']
        );
    }

    public function test_a_value_equal_to_the_catalog_is_not_stored(): void
    {
        // `tickets` is off in the catalog and `tournaments` is on, so one of these
        // is a no-op and the other is a real change. The pair is what proves
        // overrides-only on the WRITE side: a row for the first would freeze
        // today's default and outlive the deploy that changes it.
        $this->save([
            ['metric_key' => 'tickets', 'is_active' => false, 'sort_order' => 50],
            ['metric_key' => 'tournaments', 'is_active' => false],
        ])->assertOk();

        $this->assertDatabaseMissing('landing_stat_settings', ['metric_key' => 'tickets']);
        $this->assertDatabaseHas('landing_stat_settings', ['metric_key' => 'tournaments', 'is_active' => false]);
    }

    public function test_a_row_that_returns_to_the_default_is_deleted(): void
    {
        $this->save([['metric_key' => 'teams', 'label' => 'Peserta']])->assertOk();
        $this->assertDatabaseHas('landing_stat_settings', ['metric_key' => 'teams']);

        $this->save([['metric_key' => 'teams', 'label' => null]])->assertOk();
        $this->assertDatabaseMissing('landing_stat_settings', ['metric_key' => 'teams']);
    }

    public function test_every_metric_off_is_an_empty_list_not_an_error(): void
    {
        $this->getJson('/api/v1/stats')->assertOk()->assertJsonCount(4, 'data');

        $this->save(array_map(
            fn (string $key) => ['metric_key' => $key, 'is_active' => false],
            \App\Support\LandingMetrics::keys(),
        ))->assertOk();

        // Four then zero in one test: the strip has a legal empty state, and the
        // endpoint must not 500 or 404 its way into it.
        $this->getJson('/api/v1/stats')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_regular_users_cannot_read_or_write_the_catalog(): void
    {
        $user = User::factory()->create(['role' => 'organizer']);

        $this->actingAs($user, 'api')->getJson('/api/v1/admin/landing-stats')->assertForbidden();
        $this->actingAs($user, 'api')
            ->putJson('/api/v1/admin/landing-stats', ['metrics' => []])
            ->assertForbidden();
    }
}
