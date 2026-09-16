<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The team sheet a manager hands in, and the three things that make it mean
 * anything.
 *
 * Almost none of this is worth asserting on its own — of course a list of players
 * saves. What is:
 *
 *  - **The lock.** Asserting that a submitted sheet refuses an edit proves nothing
 *    by itself: a door that refuses every edit passes it. The comparison is the
 *    identical request before submitting, which must succeed, and the stored rows
 *    afterwards, which must be the ones from before.
 *  - **The sheet is drawn from that team's roster.** Asserting the manager's own
 *    player saves says nothing about whether anyone else's would. Same request,
 *    the opponent's player id, 422.
 *  - **An official is not a player.** The two lists are separate tables precisely
 *    so this cannot be typed, and a check that pooled them would pass every test
 *    above.
 */
class MatchLineupTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /**
     * An open event with one league category, two teams registered by two
     * different managers through the public form (which is what sets
     * `teams.manager_user_id`), and a fixture between them.
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

            // A fixture can only pair approved teams, so the organizer's verdict
            // is part of the scene rather than something the test skips past by
            // writing the column directly.
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
     * @param  array<int, array<string, mixed>>  $players
     * @param  array<int, array<string, mixed>>  $officials
     */
    private function save(array $side, string $match, array $players, array $officials = [])
    {
        return $this->actingAs($side['manager'], 'api')
            ->putJson("/api/v1/my-teams/{$side['team']['id']}/matches/{$match}/lineup", [
                'players' => $players,
                'officials' => $officials,
            ]);
    }

    public function test_a_submitted_sheet_refuses_the_edit_that_the_same_sheet_accepted_a_moment_earlier(): void
    {
        $scene = $this->scene();
        $home = $scene['home'];
        [$striker, $keeper] = array_column($home['team']['players'], 'id');

        // Before: the edit lands.
        $this->save($home, $scene['match'], [
            ['player_id' => $striker, 'role' => 'starter'],
            ['player_id' => $keeper, 'role' => 'substitute'],
        ])->assertOk()->assertJsonPath('data.lineup.editable', true);

        $this->actingAs($home['manager'], 'api')
            ->postJson("/api/v1/my-teams/{$home['team']['id']}/matches/{$scene['match']}/lineup/submit")
            ->assertOk()
            ->assertJsonPath('data.lineup.status', 'submitted')
            ->assertJsonPath('data.lineup.editable', false);

        // After: the identical request, nothing else changed.
        $this->save($home, $scene['match'], [
            ['player_id' => $keeper, 'role' => 'starter'],
        ])->assertStatus(422);

        // And the refusal happened before anything was written — a 422 that
        // lands after the delete looks identical from outside.
        $this->assertDatabaseCount('match_lineup_players', 2);
        $this->assertDatabaseHas('match_lineup_players', [
            'player_id' => $striker,
            'role' => 'starter',
        ]);
    }

    public function test_the_sheet_is_drawn_from_that_teams_own_roster(): void
    {
        $scene = $this->scene();
        $home = $scene['home'];
        $mine = $home['team']['players'][0]['id'];
        $theirs = $scene['away']['team']['players'][0]['id'];

        // The same shape of request twice, differing only in whose player it
        // names — so only the ownership check can account for the 422.
        $this->save($home, $scene['match'], [
            ['player_id' => $mine, 'role' => 'starter'],
        ])->assertOk();

        $this->save($home, $scene['match'], [
            ['player_id' => $theirs, 'role' => 'starter'],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('match_lineup_players', ['player_id' => $theirs]);
    }

    public function test_an_official_cannot_be_named_as_a_player(): void
    {
        $scene = $this->scene();
        $home = $scene['home'];
        $coach = $home['team']['officials'][0]['id'];

        // Named on the bench list, the coach saves. Named in the player list,
        // the same id is not a player of this team and never was — this is the
        // "ofisial bukan pemain" invariant, and one pooled table would let both
        // of these through.
        $this->save($home, $scene['match'], [], [
            ['team_official_id' => $coach],
        ])->assertOk();

        $this->save($home, $scene['match'], [
            ['player_id' => $coach, 'role' => 'starter'],
        ])->assertStatus(422);

        $this->assertDatabaseCount('match_lineup_officials', 1);
        $this->assertDatabaseCount('match_lineup_players', 0);
    }

    public function test_a_sheet_with_nobody_starting_is_not_handed_in(): void
    {
        $scene = $this->scene();
        $home = $scene['home'];
        [$striker, $keeper] = array_column($home['team']['players'], 'id');

        // Two substitutes is a form that was opened, not a team sheet.
        $this->save($home, $scene['match'], [
            ['player_id' => $striker, 'role' => 'substitute'],
            ['player_id' => $keeper, 'role' => 'substitute'],
        ])->assertOk();

        $this->actingAs($home['manager'], 'api')
            ->postJson("/api/v1/my-teams/{$home['team']['id']}/matches/{$scene['match']}/lineup/submit")
            ->assertStatus(422);

        // Promote one of them and nothing else changes.
        $this->save($home, $scene['match'], [
            ['player_id' => $striker, 'role' => 'starter'],
            ['player_id' => $keeper, 'role' => 'substitute'],
        ])->assertOk();

        $this->actingAs($home['manager'], 'api')
            ->postJson("/api/v1/my-teams/{$home['team']['id']}/matches/{$scene['match']}/lineup/submit")
            ->assertOk()
            ->assertJsonPath('data.lineup.status', 'submitted');
    }

    public function test_the_same_player_sent_back_without_a_row_id_moves_instead_of_colliding(): void
    {
        $scene = $this->scene();
        $home = $scene['home'];
        $striker = $home['team']['players'][0]['id'];

        // With the row id the editor was handed: the ordinary update.
        $rowId = $this->save($home, $scene['match'], [
            ['player_id' => $striker, 'role' => 'substitute'],
        ])->assertOk()->json('data.lineup.players.0.id');

        $this->save($home, $scene['match'], [
            ['id' => $rowId, 'player_id' => $striker, 'role' => 'starter'],
        ])->assertOk()->assertJsonPath('data.lineup.players.0.role', 'starter');

        // Without it — an editor that rebuilt its list from the roster, which is
        // what dragging a player between the two columns looks like on the wire.
        // `unique(lineup_id, player_id)` makes this a 500 unless the player is
        // treated as the row's identity.
        $this->save($home, $scene['match'], [
            ['player_id' => $striker, 'role' => 'substitute'],
        ])->assertOk()->assertJsonPath('data.lineup.players.0.role', 'substitute');

        $this->assertDatabaseCount('match_lineup_players', 1);
    }

    public function test_a_manager_reaches_only_their_own_teams_fixtures(): void
    {
        $scene = $this->scene();
        $home = $scene['home'];
        $away = $scene['away'];

        // Their own fixture, from their own team: fine.
        $this->actingAs($home['manager'], 'api')
            ->getJson("/api/v1/my-teams/{$home['team']['id']}/matches/{$scene['match']}/lineup")
            ->assertOk();

        // The same fixture, reached through the opponent's team id. `scope()`
        // never had that team, so it is a 404 and the row's existence stays
        // unconfirmed — the same shape MyTeamController already answers with.
        $this->actingAs($home['manager'], 'api')
            ->getJson("/api/v1/my-teams/{$away['team']['id']}/matches/{$scene['match']}/lineup")
            ->assertStatus(404);

        // And a fixture their own team is not in, reached through their own
        // team id — the half the scope check cannot see.
        $other = $this->scene();

        $this->actingAs($home['manager'], 'api')
            ->getJson("/api/v1/my-teams/{$home['team']['id']}/matches/{$other['match']}/lineup")
            ->assertStatus(404);
    }

    public function test_one_sheet_per_team_per_fixture_however_many_times_it_is_opened(): void
    {
        $scene = $this->scene();
        $home = $scene['home'];
        $away = $scene['away'];

        // Both managers open their own sheet twice. The row is created on read,
        // so an unguarded create here would leave four.
        foreach ([$home, $home, $away, $away] as $side) {
            $this->actingAs($side['manager'], 'api')
                ->getJson("/api/v1/my-teams/{$side['team']['id']}/matches/{$scene['match']}/lineup")
                ->assertOk();
        }

        $this->assertDatabaseCount('match_lineups', 2);
    }

    public function test_the_fixture_list_carries_each_sheets_status(): void
    {
        $scene = $this->scene();
        $home = $scene['home'];
        $striker = $home['team']['players'][0]['id'];

        // Nothing opened yet: the fixture is listed, the sheet is not there.
        $this->actingAs($home['manager'], 'api')
            ->getJson("/api/v1/my-teams/{$home['team']['id']}/matches")
            ->assertOk()
            ->assertJsonPath('data.matches.0.id', $scene['match'])
            ->assertJsonPath('data.matches.0.lineup', null);

        $this->save($home, $scene['match'], [
            ['player_id' => $striker, 'role' => 'starter'],
        ])->assertOk();

        $this->actingAs($home['manager'], 'api')
            ->postJson("/api/v1/my-teams/{$home['team']['id']}/matches/{$scene['match']}/lineup/submit")
            ->assertOk();

        // The same list now says so, without a second request per fixture.
        $this->actingAs($home['manager'], 'api')
            ->getJson("/api/v1/my-teams/{$home['team']['id']}/matches")
            ->assertOk()
            ->assertJsonPath('data.matches.0.lineup.status', 'submitted')
            ->assertJsonPath('data.matches.0.lineup.editable', false);
    }
}
