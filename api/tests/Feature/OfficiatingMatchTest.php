<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Services\EventPersonnelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * What match staff may write, and what they deliberately may not.
 *
 * The whole of this surface is the organizer's own two endpoints called through
 * a different door, so almost nothing here is worth asserting on its own — of
 * course a scoreline saves. Two things are:
 *
 *  - **An operator records; they don't ratify.** Asserting the staff's result is
 *    unconfirmed proves nothing by itself: a door that never confirms anything
 *    passes it, and so does one where `confirmed_at` is simply broken. The
 *    comparison is the same fixture saved by an org admin, which must come out
 *    confirmed.
 *  - **The staff door is not the referee door.** Asserting staff gets 200 says
 *    nothing about whether `event.staff` refuses anybody. Same request, referee
 *    account, 403.
 *
 * Everything else — the assist ceiling, the rubber refusal, the roster filter —
 * lives in MatchStatService and MatchResultService and is already covered where
 * the organizer's door exercises it. That is the point of the extraction: there
 * is only one copy to test.
 */
class OfficiatingMatchTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /**
     * An event with one league category and two registered teams, plus a
     * fixture between them. Everything goes through the organizer's own
     * endpoints so the fixture under test is the one production makes.
     *
     * @return array{0: Organization, 1: Event, 2: string, 3: string} org, event, match id, home player id
     */
    private function scene(User $owner): array
    {
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        $teams = [];

        foreach (['Home', 'Away'] as $name) {
            $teams[$name] = $this->actingAs($owner, 'api')
                ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations", [
                    'category_id' => $category->id,
                    'name' => $name,
                    'players' => [['full_name' => $name.' Striker', 'jersey_number' => '9']],
                ])
                ->assertCreated()
                ->json('data');
        }

        $match = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/matches", [
                'home_team_id' => $teams['Home']['id'],
                'away_team_id' => $teams['Away']['id'],
                'scheduled_at' => null,
            ])
            ->assertCreated()
            ->json('data.id');

        return [$org, $event, $match, $teams['Home']['players'][0]['id']];
    }

    /**
     * Put one crew member on an event and hand back their account, rotated so
     * that `password.rotated` is never what a 403 here means. Once per event:
     * sync() is a full-list write.
     */
    private function crew(Event $event, string $kind, string $email = 'crew@example.test'): User
    {
        app(EventPersonnelService::class)->sync($event, [
            ['full_name' => 'Petugas', 'kind' => $kind, 'email' => $email],
        ]);

        $user = User::where('email', $email)->firstOrFail();
        $user->forceFill(['must_change_password' => false])->save();

        return $user->fresh();
    }

    public function test_a_result_saved_by_staff_waits_for_an_admin_but_the_same_save_by_the_owner_does_not(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        [$org, $event, $match] = $this->scene($owner);
        $staff = $this->crew($event, 'staff');

        // Staff first: recorded, not ratified.
        $this->actingAs($staff, 'api')
            ->patchJson("/api/v1/officiating/events/{$event->id}/matches/{$match}", [
                'status' => 'finished', 'home_score' => 2, 'away_score' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.confirmed', false);

        $this->assertNull(
            $event->matches()->findOrFail($match)->confirmed_at,
            'staff record a result; they do not sign it off',
        );

        // The identical payload on the identical fixture, from someone who
        // administers the organization. Nothing but the door changed.
        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match}", [
                'status' => 'finished', 'home_score' => 2, 'away_score' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.confirmed', true);

        $this->assertNotNull($event->matches()->findOrFail($match)->confirmed_at);
    }

    public function test_staff_write_stats_that_the_organizer_reads_back(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        [$org, $event, $match, $player] = $this->scene($owner);
        $staff = $this->crew($event, 'staff');

        $this->actingAs($staff, 'api')
            ->putJson("/api/v1/officiating/events/{$event->id}/matches/{$match}/stats", [
                'stats' => [
                    ['player_id' => $player, 'stat_key' => 'goals', 'value' => 1],
                    ['player_id' => $player, 'stat_key' => 'yellow_cards', 'value' => 1],
                ],
            ])
            ->assertOk();

        // Read back through the *organizer's* endpoint, not the one that wrote
        // it: two editors that agreed only with themselves would pass a
        // round-trip through a single door.
        $tally = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/matches/{$match}/stats")
            ->assertOk()
            ->json("data.stats.{$player}");

        $this->assertSame(1, $tally['goals']);
        $this->assertSame(1, $tally['yellow_cards']);
    }

    public function test_the_referee_half_of_the_crew_cannot_write_results(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        [, $event, $match] = $this->scene($owner);
        $referee = $this->crew($event, 'referee');

        // Same event, same fixture, same payload as the staff test above. Only
        // `kind` differs — so only `event.staff` can account for the 403.
        $this->actingAs($referee, 'api')
            ->patchJson("/api/v1/officiating/events/{$event->id}/matches/{$match}", [
                'status' => 'finished', 'home_score' => 2, 'away_score' => 1,
            ])
            ->assertStatus(403);

        $this->actingAs($referee, 'api')
            ->putJson("/api/v1/officiating/events/{$event->id}/matches/{$match}/stats", ['stats' => []])
            ->assertStatus(403);

        $this->assertNull($event->matches()->findOrFail($match)->confirmed_at);
    }

    public function test_staff_cannot_reach_a_fixture_of_a_sibling_event(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        [, $mine] = $this->scene($owner);
        [, $theirs, $theirMatch] = $this->scene($owner);
        $staff = $this->crew($mine, 'staff');

        // Both events belong to the same organizer, so the tenant cannot be
        // what separates them — the crew's claim is to one event, and the
        // fixture lookup has to be scoped by it.
        $this->actingAs($staff, 'api')
            ->patchJson("/api/v1/officiating/events/{$theirs->id}/matches/{$theirMatch}", [
                'status' => 'finished', 'home_score' => 1, 'away_score' => 0,
            ])
            ->assertStatus(403);

        $this->assertNull($theirs->matches()->findOrFail($theirMatch)->confirmed_at);
    }
}
