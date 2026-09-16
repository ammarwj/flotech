<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\MatchLineup;
use App\Models\User;
use App\Services\EventPersonnelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The print gate, which is the most literal thing the feature was asked for:
 * *"di acc wasit kemudian baru bisa di print meja IP"*.
 *
 * Asserting that an approved fixture prints proves nothing on its own — a route
 * that prints whatever it is handed passes that. So every case here is a pair:
 *
 *  - **One side approved is still refused.** The gate reads both sheets, not the
 *    one in front of it. Half a fixture's document is not a lighter version of
 *    it, and a 200 here would mean a sheet goes on the table with one team's
 *    lineup unreviewed.
 *  - **The organizer's door answers identically.** Two routes, one action: the
 *    twin exists so the panitia can print, and asserting only the staff's 200
 *    would pass even if the organizer's copy had no gate at all.
 *  - **The crew's door is still the crew's.** A referee on the same event must be
 *    refused by `event.staff` — the sheet is the staff's job, and the role split
 *    is only worth anything if it refuses somebody.
 */
class LineupSheetTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /**
     * Same fixture LineupApprovalTest builds: two teams registered through the
     * public form (which is what sets `teams.manager_user_id`), approved, and a
     * match between them.
     *
     * @return array{event: Event, org: \App\Models\Organization, owner: User, home: array<string, mixed>, away: array<string, mixed>, match: string}
     */
    private function scene(): array
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org, attrs: [
            'registration_open' => Carbon::now()->subDay(),
            'registration_close' => Carbon::now()->addDays(10),
        ]);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        $sides = [];

        foreach (['Home', 'Away'] as $name) {
            $manager = User::factory()->create();

            $team = $this->actingAs($manager, 'api')
                ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", [
                    'category_id' => $category->id,
                    'name' => $name.' FC',
                    'contact_name' => 'Andi',
                    'contact_phone' => '08123456789',
                    'players' => [
                        ['full_name' => $name.' Striker', 'jersey_number' => '9'],
                        ['full_name' => $name.' Keeper', 'jersey_number' => '1'],
                    ],
                    'officials' => [
                        ['full_name' => $name.' Coach'],
                    ],
                ])
                ->assertCreated()
                ->json('data.team');

            $this->actingAs($owner, 'api')
                ->patchJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/{$team['id']}", [
                    'status' => 'approved',
                ])
                ->assertOk();

            $sides[$name] = ['manager' => $manager, 'team' => $team];
        }

        $match = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/matches", [
                'home_team_id' => $sides['Home']['team']['id'],
                'away_team_id' => $sides['Away']['team']['id'],
                'scheduled_at' => Carbon::now()->addDay()->toIso8601String(),
            ])
            ->assertCreated()
            ->json('data.id');

        return [
            'event' => $event,
            'org' => $org,
            'owner' => $owner,
            'home' => $sides['Home'],
            'away' => $sides['Away'],
            'match' => $match,
        ];
    }

    /**
     * The event's crew, rotation flag cleared — every route here sits behind
     * `password.rotated`, and the invite's default password is not what this
     * file is testing. Both roles in one `sync()` call: it is a full-list
     * contract, so a second call naming only staff would delete the referee.
     *
     * @param  array<string, string>  $byKind  kind => email
     * @return array<string, User>
     */
    private function crew(Event $event, array $byKind): array
    {
        app(EventPersonnelService::class)->sync($event, array_map(
            fn (string $kind, string $email) => ['full_name' => 'Petugas', 'kind' => $kind, 'email' => $email],
            array_keys($byKind),
            array_values($byKind),
        ));

        $users = [];

        foreach ($byKind as $kind => $email) {
            $user = User::where('email', $email)->firstOrFail();
            $user->forceFill(['must_change_password' => false])->save();
            $users[$kind] = $user->fresh();
        }

        return $users;
    }

    /** Fill and hand in a side's sheet. */
    private function sheet(array $side, string $match): MatchLineup
    {
        [$striker, $keeper] = array_column($side['team']['players'], 'id');

        $this->actingAs($side['manager'], 'api')
            ->putJson("/api/v1/my-teams/{$side['team']['id']}/matches/{$match}/lineup", [
                'players' => [
                    ['player_id' => $striker, 'role' => 'starter'],
                    ['player_id' => $keeper, 'role' => 'substitute'],
                ],
                'officials' => [
                    ['team_official_id' => $side['team']['officials'][0]['id']],
                ],
            ])
            ->assertOk();

        $this->actingAs($side['manager'], 'api')
            ->postJson("/api/v1/my-teams/{$side['team']['id']}/matches/{$match}/lineup/submit")
            ->assertOk();

        return MatchLineup::where('match_id', $match)
            ->where('team_id', $side['team']['id'])
            ->firstOrFail();
    }

    public function test_the_sheet_prints_only_once_both_teams_are_approved(): void
    {
        Notification::fake();

        $scene = $this->scene();
        $crew = $this->crew($scene['event'], [
            'referee' => 'wasit@example.test',
            'staff' => 'staf@example.test',
        ]);

        $home = $this->sheet($scene['home'], $scene['match']);
        $away = $this->sheet($scene['away'], $scene['match']);

        $base = "/api/v1/officiating/events/{$scene['event']->id}";
        $url = "{$base}/matches/{$scene['match']}/lineup-sheet";

        // Nothing approved yet: both sheets are in, and that is not the gate.
        $this->actingAs($crew['staff'], 'api')->get($url)->assertStatus(422);

        $this->actingAs($crew['referee'], 'api')
            ->postJson("{$base}/lineups/{$home->id}/approve")
            ->assertOk();

        // Half the fixture. Same request, same account, one signature short.
        $this->actingAs($crew['staff'], 'api')->get($url)->assertStatus(422);

        $this->actingAs($crew['referee'], 'api')
            ->postJson("{$base}/lineups/{$away->id}/approve")
            ->assertOk();

        $response = $this->actingAs($crew['staff'], 'api')->get($url)->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        // `Pdf::download()` hands back a plain Response with the bytes in it,
        // not a streamed one — `streamedContent()` throws here.
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_organizer_prints_the_same_sheet_behind_the_same_gate(): void
    {
        Notification::fake();

        $scene = $this->scene();
        $referee = $this->crew($scene['event'], ['referee' => 'wasit@example.test'])['referee'];

        $home = $this->sheet($scene['home'], $scene['match']);
        $this->sheet($scene['away'], $scene['match']);

        $url = "/api/v1/organizations/{$scene['org']->id}/matches/{$scene['match']}/lineup-sheet";

        // One side approved — the organizer's door is not a way around the
        // referee, which is the whole reason the gate lives in the controller.
        $this->actingAs($referee, 'api')
            ->postJson("/api/v1/officiating/events/{$scene['event']->id}/lineups/{$home->id}/approve")
            ->assertOk();

        $this->actingAs($scene['owner'], 'api')->get($url)->assertStatus(422);

        $away = MatchLineup::where('match_id', $scene['match'])
            ->where('team_id', $scene['away']['team']['id'])
            ->firstOrFail();

        $this->actingAs($referee, 'api')
            ->postJson("/api/v1/officiating/events/{$scene['event']->id}/lineups/{$away->id}/approve")
            ->assertOk();

        $response = $this->actingAs($scene['owner'], 'api')->get($url)->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_referee_cannot_use_the_staff_door_the_staff_can(): void
    {
        Notification::fake();

        $scene = $this->scene();
        $crew = $this->crew($scene['event'], [
            'referee' => 'wasit@example.test',
            'staff' => 'staf@example.test',
        ]);

        $base = "/api/v1/officiating/events/{$scene['event']->id}";

        foreach ([$scene['home'], $scene['away']] as $side) {
            $lineup = $this->sheet($side, $scene['match']);

            $this->actingAs($crew['referee'], 'api')
                ->postJson("{$base}/lineups/{$lineup->id}/approve")
                ->assertOk();
        }

        $url = "{$base}/matches/{$scene['match']}/lineup-sheet";

        // Approved by this very account, and still refused: printing is the
        // staff's job, and `event.staff` has to refuse somebody to mean anything.
        $this->actingAs($crew['referee'], 'api')->get($url)->assertStatus(403);

        $this->actingAs($crew['staff'], 'api')->get($url)->assertOk();
    }

    public function test_a_sheet_from_another_event_is_not_reachable(): void
    {
        Notification::fake();

        $mine = $this->scene();
        $theirs = $this->scene();

        $staff = $this->crew($mine['event'], ['staff' => 'staf@example.test'])['staff'];

        $this->actingAs($staff, 'api')
            ->get("/api/v1/officiating/events/{$mine['event']->id}/matches/{$theirs['match']}/lineup-sheet")
            ->assertStatus(404);
    }
}
