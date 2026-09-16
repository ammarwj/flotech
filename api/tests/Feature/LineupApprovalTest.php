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
 * The referee's verdict on a team sheet.
 *
 * Asserting that an approval works proves very little — a door that stamps
 * anything handed to it passes that. What the three comparisons here are for:
 *
 *  - **`submitted` is the only legal starting point.** The same request against a
 *    sheet the manager is still drafting must be refused, or the approval means
 *    nothing but "somebody pressed the button first".
 *  - **Approval is terminal.** Rejecting an approved sheet must fail, because the
 *    print gate downstream reads `approved` and a way back reopens editing on a
 *    sheet that may already be on the table.
 *  - **The referee door is not the staff door.** Staff on the same event, on the
 *    same route, must be refused — asserting the referee's 200 says nothing about
 *    whether `event.referee` ever refuses anybody.
 */
class LineupApprovalTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /**
     * Two teams registered through the public form (which is what sets
     * `teams.manager_user_id`), a fixture between them, and both sheets filled
     * in — one submitted, one still a draft, so the two states can be compared
     * inside a single fixture.
     *
     * @return array{event: Event, home: array<string, mixed>, away: array<string, mixed>, match: string}
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
                'scheduled_at' => null,
            ])
            ->assertCreated()
            ->json('data.id');

        return [
            'event' => $event,
            'home' => $sides['Home'],
            'away' => $sides['Away'],
            'match' => $match,
        ];
    }

    /**
     * The event's crew, with the rotation flag cleared — every route here sits
     * behind `password.rotated`, and the invite's default password is not what
     * this file is testing.
     *
     * Both roles are seeded in one call because `sync()` is the full-list
     * contract: a second call naming only the staff would delete the referee's
     * row, and the referee would then be refused for a reason that has nothing
     * to do with what is under test.
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

    /** Fill a side's sheet; submit it only when asked to. */
    private function sheet(array $side, string $match, bool $submit): MatchLineup
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

        if ($submit) {
            $this->actingAs($side['manager'], 'api')
                ->postJson("/api/v1/my-teams/{$side['team']['id']}/matches/{$match}/lineup/submit")
                ->assertOk();
        }

        return MatchLineup::where('match_id', $match)
            ->where('team_id', $side['team']['id'])
            ->firstOrFail();
    }

    public function test_only_a_submitted_sheet_can_be_approved(): void
    {
        Notification::fake();

        $scene = $this->scene();
        $referee = $this->crew($scene['event'], ['referee' => 'wasit@example.test'])['referee'];

        $submitted = $this->sheet($scene['home'], $scene['match'], submit: true);
        $draft = $this->sheet($scene['away'], $scene['match'], submit: false);

        $base = "/api/v1/officiating/events/{$scene['event']->id}";

        $this->actingAs($referee, 'api')
            ->postJson("{$base}/lineups/{$submitted->id}/approve")
            ->assertOk();

        // Same request, same referee, same fixture — the only difference is that
        // this sheet was never handed in.
        $this->actingAs($referee, 'api')
            ->postJson("{$base}/lineups/{$draft->id}/approve")
            ->assertStatus(422);

        $this->assertSame('approved', $submitted->fresh()->status);
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_approval_is_terminal_but_a_rejection_hands_the_sheet_back(): void
    {
        Notification::fake();

        $scene = $this->scene();
        $referee = $this->crew($scene['event'], ['referee' => 'wasit@example.test'])['referee'];

        $home = $this->sheet($scene['home'], $scene['match'], submit: true);
        $away = $this->sheet($scene['away'], $scene['match'], submit: true);

        $base = "/api/v1/officiating/events/{$scene['event']->id}";

        // Rejected: the reason is stored and the manager may edit again.
        $this->actingAs($referee, 'api')
            ->postJson("{$base}/lineups/{$away->id}/reject", ['note' => 'Nomor punggung ganda.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.note', 'Nomor punggung ganda.')
            ->assertJsonPath('data.editable', true);

        // Approved: locked, and the identical reject is refused.
        $this->actingAs($referee, 'api')
            ->postJson("{$base}/lineups/{$home->id}/approve")
            ->assertOk();

        $this->actingAs($referee, 'api')
            ->postJson("{$base}/lineups/{$home->id}/reject", ['note' => 'Berubah pikiran.'])
            ->assertStatus(422);

        $this->assertSame('approved', $home->fresh()->status);
        $this->assertNull($home->fresh()->note);
    }

    public function test_a_rejection_without_a_reason_is_refused(): void
    {
        Notification::fake();

        $scene = $this->scene();
        $referee = $this->crew($scene['event'], ['referee' => 'wasit@example.test'])['referee'];
        $home = $this->sheet($scene['home'], $scene['match'], submit: true);

        $this->actingAs($referee, 'api')
            ->postJson("/api/v1/officiating/events/{$scene['event']->id}/lineups/{$home->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonPath('errors.note.0', fn ($m) => is_string($m));

        // Still waiting on the referee, not silently sent back.
        $this->assertSame('submitted', $home->fresh()->status);
    }

    public function test_staff_cannot_use_the_referee_door_the_referee_can(): void
    {
        Notification::fake();

        $scene = $this->scene();
        $crew = $this->crew($scene['event'], [
            'referee' => 'wasit@example.test',
            'staff' => 'staf@example.test',
        ]);
        [$referee, $staff] = [$crew['referee'], $crew['staff']];

        $home = $this->sheet($scene['home'], $scene['match'], submit: true);
        $base = "/api/v1/officiating/events/{$scene['event']->id}";

        $this->actingAs($staff, 'api')
            ->getJson("{$base}/matches/{$scene['match']}/lineups")
            ->assertStatus(403);

        $this->actingAs($staff, 'api')
            ->postJson("{$base}/lineups/{$home->id}/approve")
            ->assertStatus(403);

        // The same two requests, the referee account.
        $this->actingAs($referee, 'api')
            ->getJson("{$base}/matches/{$scene['match']}/lineups")
            ->assertOk()
            ->assertJsonPath('data.home.status', 'submitted')
            ->assertJsonPath('data.away', null);

        $this->actingAs($referee, 'api')
            ->postJson("{$base}/lineups/{$home->id}/approve")
            ->assertOk();
    }

    public function test_a_referee_cannot_reach_a_sheet_from_another_event(): void
    {
        Notification::fake();

        $mine = $this->scene();
        $theirs = $this->scene();

        $referee = $this->crew($mine['event'], ['referee' => 'wasit@example.test'])['referee'];
        $foreign = $this->sheet($theirs['home'], $theirs['match'], submit: true);

        $this->actingAs($referee, 'api')
            ->postJson("/api/v1/officiating/events/{$mine['event']->id}/lineups/{$foreign->id}/approve")
            ->assertStatus(404);

        $this->assertSame('submitted', $foreign->fresh()->status);
    }
}
