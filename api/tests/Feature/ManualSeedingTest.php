<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manual seeding takes the organizer's bracket exactly as drawn.
 *
 * Two things used to be decided for them: empty slots were topped up with
 * whoever was left over, and every kickoff was reassigned by the slot allocator
 * moments after generation. Both are gone, so what is asserted here is mostly
 * *absence* — and absence is easy to assert vacuously. The overrides are
 * therefore always checked against an untouched tie in the same request: a bare
 * "the locked tie kept its time" would still pass if the allocator never ran.
 */
class ManualSeedingTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    private function knockoutEvent(Organization $org, int $teams = 8, string $timezone = 'Asia/Jakarta'): Event
    {
        $event = $org->events()->create([
            'name' => 'Knockout Cup',
            'slug' => 'knockout-cup-'.uniqid(),
            'sport_type' => 'mini_soccer',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
            'timezone' => $timezone,
        ]);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'knockout_single',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        foreach (range(1, $teams) as $i) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => 'Team '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'status' => 'approved',
                'contact_name' => 'PIC',
                'contact_phone' => '0800',
            ]);
        }

        return $event->load('categories');
    }

    private function scheduleUrl(Organization $org, Event $event): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$event->id}"
            ."/categories/{$event->categories->first()->id}/schedule";
    }

    /** Team ids in name order — the same order automatic seeding would use. */
    private function teamIds(Event $event): array
    {
        return $event->teams()->orderBy('name')->pluck('id')->all();
    }

    public function test_it_rejects_a_seeding_that_leaves_a_team_out_of_the_bracket(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, teams: 4);
        $ids = $this->teamIds($event);

        // Team 04 is placed nowhere. Nothing tops the gap up any more, so it
        // would simply never play.
        $this->actingAs($user, 'api')
            ->postJson($this->scheduleUrl($org, $event), [
                'seeding' => 'manual',
                'pairs' => [
                    ['order' => 0, 'home_team_id' => $ids[0], 'away_team_id' => $ids[1]],
                    ['order' => 1, 'home_team_id' => $ids[2], 'away_team_id' => null],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.pairs.0', 'Ada 1 tim yang belum ditempatkan.');

        $this->assertSame(0, $event->matches()->count(), 'a rejected payload must not build a bracket');
    }

    public function test_an_emptied_slot_stays_empty(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        // Five teams pad to a bracket of eight, so there are four first-round
        // slots for three occupied ties — one is left with nobody at all.
        $event = $this->knockoutEvent($org, teams: 5);
        $ids = $this->teamIds($event);

        $this->actingAs($user, 'api')
            ->postJson($this->scheduleUrl($org, $event), [
                'seeding' => 'manual',
                'pairs' => [
                    ['order' => 0, 'home_team_id' => $ids[0], 'away_team_id' => $ids[1]],
                    ['order' => 1, 'home_team_id' => null, 'away_team_id' => null],
                    ['order' => 2, 'home_team_id' => $ids[2], 'away_team_id' => $ids[3]],
                    ['order' => 3, 'home_team_id' => $ids[4], 'away_team_id' => null],
                ],
            ])
            ->assertCreated();

        $empty = $event->matches()->where('round', 1)->where('order', 1)->firstOrFail();

        $this->assertNull($empty->home_team_id);
        $this->assertNull($empty->away_team_id);
        // An empty tie is not a walkover: there is nobody to walk over.
        $this->assertSame('scheduled', $empty->status);
        $this->assertNull($empty->confirmed_at);

        // The lone team in slot 3 is still a bye, and still advances.
        $bye = $event->matches()->where('round', 1)->where('order', 3)->firstOrFail();
        $this->assertSame('finished', $bye->status);
        $this->assertSame($ids[4], $event->matches()->where('round', 2)->where('order', 1)->firstOrFail()->away_team_id);
    }

    public function test_a_lone_team_sent_as_the_away_side_becomes_a_bye(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, teams: 4);
        $ids = $this->teamIds($event);

        $this->actingAs($user, 'api')
            ->postJson($this->scheduleUrl($org, $event), [
                'seeding' => 'manual',
                'pairs' => [
                    ['order' => 0, 'home_team_id' => $ids[0], 'away_team_id' => $ids[1]],
                    // Only an away side: nothing to play against, so it has to
                    // be read as a bye rather than an unwinnable tie.
                    ['order' => 1, 'home_team_id' => null, 'away_team_id' => $ids[2]],
                ],
            ])
            // Team 04 is placed nowhere, so this is refused for that reason —
            // the swap is asserted on a payload that gets through, below.
            ->assertStatus(422);

        $this->actingAs($user, 'api')
            ->postJson($this->scheduleUrl($org, $event), [
                'seeding' => 'manual',
                'pairs' => [
                    ['order' => 0, 'home_team_id' => $ids[0], 'away_team_id' => $ids[1]],
                    ['order' => 1, 'home_team_id' => $ids[3], 'away_team_id' => null],
                ],
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'api')
            ->postJson($this->scheduleUrl($org, $event), [
                'seeding' => 'manual',
                'pairs' => [
                    ['order' => 0, 'home_team_id' => $ids[0], 'away_team_id' => null],
                    ['order' => 1, 'home_team_id' => null, 'away_team_id' => $ids[1]],
                ],
            ])
            ->assertStatus(422);

        // With every team placed, the lone away side lands at home as a bye.
        $event2 = $this->knockoutEvent($org, teams: 3);
        $ids2 = $this->teamIds($event2);

        $this->actingAs($user, 'api')
            ->postJson($this->scheduleUrl($org, $event2), [
                'seeding' => 'manual',
                'pairs' => [
                    ['order' => 0, 'home_team_id' => $ids2[0], 'away_team_id' => $ids2[1]],
                    ['order' => 1, 'home_team_id' => null, 'away_team_id' => $ids2[2]],
                ],
            ])
            ->assertCreated();

        $bye = $event2->matches()->where('round', 1)->where('order', 1)->firstOrFail();

        $this->assertSame($ids2[2], $bye->home_team_id);
        $this->assertNull($bye->away_team_id);
        $this->assertSame('finished', $bye->status);
    }

    public function test_a_ties_own_kickoff_and_venue_survive_the_slot_allocator(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, teams: 4);
        $ids = $this->teamIds($event);

        $this->actingAs($user, 'api')
            ->postJson($this->scheduleUrl($org, $event), [
                'seeding' => 'manual',
                'venues' => 2,
                'daily_start' => '08:00',
                'daily_end' => '20:00',
                'pairs' => [
                    [
                        'order' => 0,
                        'home_team_id' => $ids[0],
                        'away_team_id' => $ids[1],
                        'scheduled_at' => '2026-08-05T19:30:00+07:00',
                        'venue' => 'Lapangan Utama',
                    ],
                    // Deliberately left to the allocator, as the control.
                    ['order' => 1, 'home_team_id' => $ids[2], 'away_team_id' => $ids[3]],
                ],
            ])
            ->assertCreated();

        $locked = $event->matches()->where('round', 1)->where('order', 0)->firstOrFail();
        $auto = $event->matches()->where('round', 1)->where('order', 1)->firstOrFail();

        // 19:30 WIB is 12:30 UTC. Getting this wrong is how the fixture ends up
        // seven hours out, so the instant is asserted, not just the date.
        $this->assertSame('2026-08-05 12:30:00', $locked->scheduled_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('Lapangan Utama', $locked->venue);

        // The control proves the allocator actually ran: it would otherwise be
        // possible to pass this test by never scheduling anything at all.
        $this->assertNotSame(
            $locked->scheduled_at->utc()->format('Y-m-d H:i:s'),
            $auto->scheduled_at->utc()->format('Y-m-d H:i:s'),
        );
        // Lane 2, not lane 1: the locked tie still consumes its slot, so the
        // fixtures around it land where they would have without the override.
        $this->assertSame('Lapangan 2', $auto->venue);
    }

    public function test_automatic_seeding_is_unchanged(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, teams: 6);
        $ids = $this->teamIds($event);

        $this->actingAs($user, 'api')
            ->postJson($this->scheduleUrl($org, $event))
            ->assertCreated();

        // Six teams pad to a bracket of eight: the top two seeds get the byes,
        // and every slot is filled because nobody asked for an empty one.
        $round1 = $event->matches()->where('round', 1)->orderBy('order')->get();

        $this->assertCount(4, $round1);
        $this->assertSame($ids[0], $round1[0]->home_team_id);
        $this->assertNull($round1[0]->away_team_id);
        $this->assertSame('finished', $round1[0]->status);
        $this->assertSame($ids[1], $round1[1]->home_team_id);
        $this->assertNull($round1[1]->away_team_id);

        foreach ($round1->slice(2) as $m) {
            $this->assertNotNull($m->home_team_id);
            $this->assertNotNull($m->away_team_id);
        }
    }
}
