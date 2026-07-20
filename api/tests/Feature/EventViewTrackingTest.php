<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Services\EventViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventViewTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126.0 Safari/537.36';

    private function org(User $owner): Organization
    {
        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id,
        ]);
    }

    private function event(Organization $org, string $status = 'open'): Event
    {
        return $org->events()->create([
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'tournament_format' => 'league', 'status' => $status,
            'start_date' => '2026-08-01', 'end_date' => '2026-08-02',
        ]);
    }

    /** Fire the beacon as a specific visitor (IP + user agent). */
    private function visit(Organization $org, Event $event, string $ip = '203.0.113.9', string $ua = self::BROWSER)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(['User-Agent' => $ua])
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/view");
    }

    public function test_beacon_records_a_view_and_a_unique_visitor(): void
    {
        $org = $this->org(User::factory()->create());
        $event = $this->event($org);

        $this->visit($org, $event)->assertStatus(202);

        $this->assertDatabaseHas('event_view_daily', [
            'event_id' => $event->id,
            'organization_id' => $org->id,
            'views' => 1,
            'unique_visitors' => 1,
        ]);
    }

    public function test_second_hit_from_same_visitor_same_day_counts_a_view_but_not_a_visitor(): void
    {
        $org = $this->org(User::factory()->create());
        $event = $this->event($org);

        $this->visit($org, $event);
        $this->visit($org, $event);

        $row = DB::table('event_view_daily')->where('event_id', $event->id)->first();

        $this->assertSame(2, (int) $row->views);
        $this->assertSame(1, (int) $row->unique_visitors);
    }

    public function test_a_different_user_agent_is_a_different_visitor(): void
    {
        $org = $this->org(User::factory()->create());
        $event = $this->event($org);

        $this->visit($org, $event);
        $this->visit($org, $event, ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit/605.1 Safari/604.1');

        $row = DB::table('event_view_daily')->where('event_id', $event->id)->first();

        $this->assertSame(2, (int) $row->views);
        $this->assertSame(2, (int) $row->unique_visitors);
    }

    public function test_the_same_visitor_counts_again_on_a_new_day(): void
    {
        $org = $this->org(User::factory()->create());
        $event = $this->event($org);

        Carbon::setTestNow('2026-07-20 10:00:00');
        $this->visit($org, $event);

        Carbon::setTestNow('2026-07-21 10:00:00');
        $this->visit($org, $event);

        $rows = DB::table('event_view_daily')->where('event_id', $event->id)->orderBy('viewed_on')->get();

        $this->assertCount(2, $rows);
        $this->assertSame(1, (int) $rows[0]->unique_visitors);
        $this->assertSame(1, (int) $rows[1]->unique_visitors);

        Carbon::setTestNow();
    }

    public function test_bot_traffic_is_dropped_but_browsers_on_the_same_event_are_not(): void
    {
        $org = $this->org(User::factory()->create());
        $event = $this->event($org);

        $this->visit($org, $event, ua: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
            ->assertStatus(202);

        // Nothing recorded — and the comparison below proves that is the bot
        // filter working rather than the endpoint being broken.
        $this->assertDatabaseCount('event_view_daily', 0);

        $this->visit($org, $event, ip: '198.51.100.7');

        $this->assertDatabaseCount('event_view_daily', 1);
    }

    public function test_draft_events_have_no_public_page_to_count(): void
    {
        $org = $this->org(User::factory()->create());
        $event = $this->event($org, status: 'draft');

        $this->visit($org, $event)->assertStatus(404);

        $this->assertDatabaseCount('event_view_daily', 0);
    }

    public function test_the_raw_ip_address_is_never_stored(): void
    {
        $org = $this->org(User::factory()->create());
        $event = $this->event($org);

        $this->visit($org, $event, ip: '203.0.113.9');

        $stored = DB::table('event_view_visitors')->get()->toJson();

        $this->assertStringNotContainsString('203.0.113.9', $stored);
    }

    public function test_a_visitors_hash_does_not_correlate_across_days(): void
    {
        $views = app(EventViewService::class);

        $monday = $views->visitorHash('203.0.113.9', self::BROWSER, '2026-07-20');
        $tuesday = $views->visitorHash('203.0.113.9', self::BROWSER, '2026-07-21');

        $this->assertNotSame($monday, $tuesday);
    }
}
