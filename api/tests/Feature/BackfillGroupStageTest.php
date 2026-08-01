<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * `matches:backfill-group-stage` — stamps the stage onto hybrid group fixtures
 * typed in before storeManual() derived it, so the bracket gate can finally see
 * a group stage the standings were already counting.
 */
class BackfillGroupStageTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /** A hybrid category with two groups of two, all drawn. */
    private function hybridEvent(): Event
    {
        $user = User::factory()->create();
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);
        $org = Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $user->id, 'plan_id' => $plan->id,
        ]);

        $event = $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Hybrid Cup',
            'slug' => 'hybrid-cup-'.uniqid(),
            'sport_type' => 'mini_soccer',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'hybrid',
            'registration_fee' => 0,
            'sort_order' => 0,
            'bracket_config' => ['groups' => 2, 'teams_per_group' => 2],
        ]);

        foreach (['A', 'A', 'B', 'B'] as $i => $group) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => "Team {$group}".($i + 1),
                'group_name' => $group,
                'status' => 'approved',
                'contact_name' => 'PIC',
                'contact_phone' => '0800',
            ]);
        }

        return $event->load('categories');
    }

    /** A stage-null fixture, as the old manual dialog left them. */
    private function looseMatch(Event $event, string $home, string $away, string $status = 'finished'): GameMatch
    {
        return GameMatch::create([
            'event_id' => $event->id,
            'category_id' => $event->categories->first()->id,
            'stage' => null,
            'group_name' => null,
            'round' => 1,
            'leg' => 1,
            'order' => 0,
            'home_team_id' => $home,
            'away_team_id' => $away,
            'status' => $status,
        ]);
    }

    /**
     * Compared, not asserted alone: the command's whole job is telling an
     * intra-group fixture from an inter-group one, so a test that only checks the
     * stamped row would pass just as well if it stamped everything.
     */
    public function test_it_stamps_intra_group_fixtures_and_leaves_the_rest_alone(): void
    {
        $event = $this->hybridEvent();
        [$a1, $a2] = $event->teams()->where('group_name', 'A')->pluck('id')->all();
        $b1 = $event->teams()->where('group_name', 'B')->value('id');

        $inGroup = $this->looseMatch($event, $a1, $a2);
        $across = $this->looseMatch($event, $a1, $b1);

        $this->artisan('matches:backfill-group-stage')->assertSuccessful();

        $inGroup->refresh();
        $this->assertSame('group', $inGroup->stage);
        $this->assertSame('A', $inGroup->group_name);
        // Presentation only, and renumbering would reshuffle matchday headings.
        $this->assertSame(1, $inGroup->round);

        $across->refresh();
        $this->assertNull($across->stage);
        $this->assertNull($across->group_name);
    }

    public function test_dry_run_changes_nothing_and_a_second_run_is_a_no_op(): void
    {
        $event = $this->hybridEvent();
        [$a1, $a2] = $event->teams()->where('group_name', 'A')->pluck('id')->all();
        $match = $this->looseMatch($event, $a1, $a2);

        $this->artisan('matches:backfill-group-stage --dry-run')->assertSuccessful();
        $this->assertNull($match->refresh()->stage);

        $this->artisan('matches:backfill-group-stage')->assertSuccessful();
        $stampedAt = $match->refresh()->updated_at;

        // Idempotent: the row no longer matches `stage IS NULL`, so nothing is
        // rewritten — webhooks and cron aside, this is meant to be safe to rerun.
        $this->artisan('matches:backfill-group-stage')
            ->expectsOutputToContain('0 laga ditandai')
            ->assertSuccessful();
        $this->assertEquals($stampedAt, $match->refresh()->updated_at);
    }

    /**
     * The gate reads anything not `finished` as pending, so stamping a cancelled
     * fixture would block the bracket for good — the same silent blocker this
     * command exists to clear.
     */
    public function test_a_cancelled_fixture_is_left_alone(): void
    {
        $event = $this->hybridEvent();
        [$a1, $a2] = $event->teams()->where('group_name', 'A')->pluck('id')->all();
        $cancelled = $this->looseMatch($event, $a1, $a2, status: 'cancelled');

        $this->artisan('matches:backfill-group-stage')->assertSuccessful();

        $this->assertNull($cancelled->refresh()->stage);
    }

    public function test_a_league_category_is_never_touched(): void
    {
        $event = $this->hybridEvent();
        $event->categories->first()->update(['tournament_format' => 'league']);
        [$a1, $a2] = $event->teams()->where('group_name', 'A')->pluck('id')->all();
        $match = $this->looseMatch($event, $a1, $a2);

        // A league fixture's null stage is what the standings read it by; the
        // group_name on its teams is leftover from a format it no longer runs.
        $this->artisan('matches:backfill-group-stage')->assertSuccessful();

        $this->assertNull($match->refresh()->stage);
    }

    public function test_an_unknown_category_fails_rather_than_reporting_nothing_to_do(): void
    {
        $this->artisan('matches:backfill-group-stage --category=019f0000-0000-7000-8000-000000000000')
            ->assertFailed();
    }
}
