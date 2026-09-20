<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * A suspended player cannot be named on the team sheet.
 *
 * Every test here compares two things that must come out different — a banned
 * player against a clean one in the same category, a ban already served against
 * one still owed, a sport with cards against one without. Asserting only that
 * "the banned player is refused" passes just as happily against a door that
 * refuses everybody, which is the failure mode this gate would actually have.
 *
 * Nothing about a ban is stored, so the last test spends its cards and takes them
 * back: correcting a typo downward must hand the player back immediately, which
 * is the property that made DisciplineService derive suspensions in the first
 * place.
 */
class LineupDisciplineTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private User $owner;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->org = $this->orgFor($this->owner);
    }

    // ---- fixtures ----

    /**
     * An event with one league category. The sport is real, from the seeder —
     * a synthetic one with hand-written stats is how the platform_fee_percent
     * bug passed twelve green tests.
     */
    private function event(string $sport = 'football'): Event
    {
        $event = $this->eventOn($this->org, attrs: ['sport_type' => $sport]);

        $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        return $event->load('categories');
    }

    private function categoryId(Event $event): string
    {
        return $event->categories->first()->id;
    }

    /**
     * A team with a manager account behind it, registered through the public
     * form — that is what sets `teams.manager_user_id`, and `managedTeams()` is
     * the only thing that proves the team is theirs.
     *
     * @param  array<int, string>  $players
     * @return array{manager: User, id: string, players: array<string, string>}
     */
    private function team(Event $event, string $name, array $players): array
    {
        $manager = User::factory()->create();

        $data = $this->actingAs($manager, 'api')
            ->postJson("/api/v1/public/events/{$this->org->slug}/{$event->slug}/register", [
                'category_id' => $this->categoryId($event),
                'name' => $name,
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
                'players' => array_map(
                    fn (string $p, int $i) => ['full_name' => $p, 'jersey_number' => (string) ($i + 1)],
                    $players,
                    array_keys($players),
                ),
            ])
            ->assertCreated()
            ->json('data.team');

        // A fixture can only pair approved teams, so the organizer's verdict is
        // part of the scene rather than a column the test writes directly.
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/v1/organizations/{$this->org->id}/events/{$event->id}/registrations/{$data['id']}", [
                'status' => 'approved',
            ])
            ->assertOk();

        return [
            'manager' => $manager,
            'id' => $data['id'],
            'players' => collect($data['players'])->pluck('id', 'full_name')->all(),
        ];
    }

    private function fixture(Event $event, string $home, string $away, ?string $kickoff = null): string
    {
        return $this->actingAs($this->owner, 'api')
            ->postJson("/api/v1/organizations/{$this->org->id}/events/{$event->id}/categories/{$this->categoryId($event)}/matches", [
                'home_team_id' => $home,
                'away_team_id' => $away,
                'scheduled_at' => $kickoff,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /** The owner administers the org, so this signs the result off in one call. */
    private function finish(string $matchId): void
    {
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/v1/organizations/{$this->org->id}/matches/{$matchId}", [
                'status' => 'finished', 'home_score' => 1, 'away_score' => 0,
            ])
            ->assertOk();
    }

    /** @param  array<string, array{yellow?: int, red?: int}>  $cards */
    private function cards(string $matchId, array $cards): void
    {
        $stats = [];

        foreach ($cards as $playerId => $card) {
            foreach (['yellow' => 'yellow_cards', 'red' => 'red_cards'] as $slot => $key) {
                if (($card[$slot] ?? 0) > 0) {
                    $stats[] = ['player_id' => $playerId, 'stat_key' => $key, 'value' => $card[$slot]];
                }
            }
        }

        $this->actingAs($this->owner, 'api')
            ->putJson("/api/v1/organizations/{$this->org->id}/matches/{$matchId}/stats", ['stats' => $stats])
            ->assertOk();
    }

    /**
     * @param  array{manager: User, id: string, players: array<string, string>}  $side
     * @param  array<int, array<string, mixed>>  $players
     */
    private function save(array $side, string $match, array $players)
    {
        return $this->actingAs($side['manager'], 'api')
            ->putJson("/api/v1/my-teams/{$side['id']}/matches/{$match}/lineup", [
                'players' => $players,
                'officials' => [],
            ]);
    }

    /** @param  array{manager: User, id: string, players: array<string, string>}  $side */
    private function submit(array $side, string $match)
    {
        return $this->actingAs($side['manager'], 'api')
            ->postJson("/api/v1/my-teams/{$side['id']}/matches/{$match}/lineup/submit");
    }

    /**
     * Give Budi three yellows across three finished fixtures, leaving one more
     * fixture unplayed for the sheet under test.
     *
     * Andi picks up a card in two of them and stays under the threshold — the
     * comparison every test below rests on, since only the card count separates
     * the two players.
     *
     * @return array{event: Event, home: array<string, mixed>, away: array<string, mixed>, next: string}
     */
    private function scene(): array
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi', 'Andi']);
        $away = $this->team($event, 'Rajawali United', ['Lawan']);

        foreach ([1, 2, 3] as $n) {
            $played = $this->fixture($event, $home['id'], $away['id'], "2026-08-0{$n} 10:00:00");
            $this->finish($played);
            $this->cards($played, $n === 3
                ? [$home['players']['Budi'] => ['yellow' => 1]]
                : [$home['players']['Budi'] => ['yellow' => 1], $home['players']['Andi'] => ['yellow' => 1]]);
        }

        return [
            'event' => $event,
            'home' => $home,
            'away' => $away,
            'next' => $this->fixture($event, $home['id'], $away['id'], '2026-08-09 10:00:00'),
        ];
    }

    // ---- tests ----

    public function test_a_suspended_player_is_refused_where_a_teammate_on_the_same_sheet_is_not(): void
    {
        $scene = $this->scene();
        $home = $scene['home'];

        // Same category, same fixture, same request shape: only which player it
        // names differs, so nothing but the ban can account for the 422.
        $this->save($home, $scene['next'], [
            ['player_id' => $home['players']['Andi'], 'role' => 'starter'],
        ])->assertOk();

        $this->save($home, $scene['next'], [
            ['player_id' => $home['players']['Budi'], 'role' => 'starter'],
        ])->assertStatus(422)
            ->assertJsonPath('errors.players.0', 'Budi sedang menjalani larangan bermain dan tidak bisa dimainkan di laga ini.');

        // And the refusal happened before anything was written — a 422 that
        // lands after the full-list delete looks identical from outside.
        $this->assertDatabaseHas('match_lineup_players', ['player_id' => $home['players']['Andi']]);
        $this->assertDatabaseMissing('match_lineup_players', ['player_id' => $home['players']['Budi']]);
    }

    public function test_a_draft_that_was_legal_when_saved_is_refused_when_it_is_handed_in(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi', 'Andi']);
        $away = $this->team($event, 'Rajawali United', ['Lawan']);

        // Two fixtures' worth of cards: one short of the threshold.
        foreach ([1, 2] as $n) {
            $played = $this->fixture($event, $home['id'], $away['id'], "2026-08-0{$n} 10:00:00");
            $this->finish($played);
            $this->cards($played, [$home['players']['Budi'] => ['yellow' => 1]]);
        }

        $next = $this->fixture($event, $home['id'], $away['id'], '2026-08-09 10:00:00');

        // The draft is legal now, and saves.
        $this->save($home, $next, [
            ['player_id' => $home['players']['Budi'], 'role' => 'starter'],
            ['player_id' => $home['players']['Andi'], 'role' => 'substitute'],
        ])->assertOk();

        // The third yellow lands on a fixture played in between and confirmed
        // afterwards. The manager has touched nothing.
        $late = $this->fixture($event, $home['id'], $away['id'], '2026-08-05 10:00:00');
        $this->finish($late);
        $this->cards($late, [$home['players']['Budi'] => ['yellow' => 1]]);

        // Handing in the stored sheet must now fail — this is what proves the
        // gate in submit() is not a copy of the one in sync().
        $this->submit($home, $next)->assertStatus(422);

        // Drop the banned player and the identical action goes through.
        $this->save($home, $next, [
            ['player_id' => $home['players']['Andi'], 'role' => 'starter'],
        ])->assertOk();

        $this->submit($home, $next)
            ->assertOk()
            ->assertJsonPath('data.lineup.status', 'submitted');
    }

    public function test_a_ban_already_served_does_not_refuse_the_fixture_after_it(): void
    {
        $scene = $this->scene();
        $home = $scene['home'];
        $budi = $home['players']['Budi'];

        // The fixture the ban falls on refuses him.
        $this->save($home, $scene['next'], [
            ['player_id' => $budi, 'role' => 'starter'],
        ])->assertStatus(422);

        // He sits it out: that fixture is played and signed off with no cards.
        $this->finish($scene['next']);

        // The next one takes him — same player, same team, same request. Only
        // the ban's status changed, from 'upcoming' to 'served'.
        $after = $this->fixture($scene['event'], $home['id'], $scene['away']['id'], '2026-08-10 10:00:00');

        $this->save($home, $after, [
            ['player_id' => $budi, 'role' => 'starter'],
        ])->assertOk();

        $this->assertDatabaseHas('match_lineup_players', ['player_id' => $budi, 'role' => 'starter']);
    }

    public function test_a_sport_without_cards_refuses_nobody(): void
    {
        // Volleyball fields squads and keeps a leaderboard, but has no card
        // column at all — a gate borrowed from showsPlayerStats() would switch
        // this feature on for a sport it can never fire in.
        $event = $this->event('volleyball');
        $home = $this->team($event, 'Garuda VC', ['Budi', 'Andi']);
        $away = $this->team($event, 'Rajawali VC', ['Lawan']);

        $next = $this->fixture($event, $home['id'], $away['id'], '2026-08-09 10:00:00');

        $data = $this->actingAs($home['manager'], 'api')
            ->getJson("/api/v1/my-teams/{$home['id']}/matches/{$next}/lineup")
            ->assertOk()
            ->json('data');

        $this->assertSame([], $data['bans']);
        $this->assertNull($data['discipline_rules']);

        $this->save($home, $next, [
            ['player_id' => $home['players']['Budi'], 'role' => 'starter'],
        ])->assertOk();
    }

    public function test_the_editor_is_handed_its_own_teams_bans_and_not_the_opponents(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi', 'Andi']);
        $away = $this->team($event, 'Rajawali United', ['Joko']);

        // One player suspended on each side, from the same fixtures.
        foreach ([1, 2, 3] as $n) {
            $played = $this->fixture($event, $home['id'], $away['id'], "2026-08-0{$n} 10:00:00");
            $this->finish($played);
            $this->cards($played, [
                $home['players']['Budi'] => ['yellow' => 1],
                $away['players']['Joko'] => ['yellow' => 1],
            ]);
        }

        $next = $this->fixture($event, $home['id'], $away['id'], '2026-08-09 10:00:00');

        $data = $this->actingAs($home['manager'], 'api')
            ->getJson("/api/v1/my-teams/{$home['id']}/matches/{$next}/lineup")
            ->assertOk()
            ->json('data');

        $ids = array_column($data['bans'], 'player_id');

        // Both halves matter: the opponent is banned too, so asserting only that
        // Budi is listed would pass against a payload that leaks everybody.
        $this->assertContains($home['players']['Budi'], $ids);
        $this->assertNotContains($away['players']['Joko'], $ids);
        $this->assertNotContains($home['players']['Andi'], $ids);
        $this->assertSame('yellow_accumulation', $data['bans'][0]['reason']);
        $this->assertSame(3, $data['discipline_rules']['yellow_threshold']);
    }

    public function test_correcting_the_cards_downward_hands_the_player_straight_back(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi']);
        $away = $this->team($event, 'Rajawali United', ['Lawan']);

        $played = $this->fixture($event, $home['id'], $away['id'], '2026-08-01 10:00:00');
        $this->finish($played);
        $this->cards($played, [$home['players']['Budi'] => ['yellow' => 3]]);

        $next = $this->fixture($event, $home['id'], $away['id'], '2026-08-09 10:00:00');

        $this->save($home, $next, [
            ['player_id' => $home['players']['Budi'], 'role' => 'starter'],
        ])->assertStatus(422);

        // The organizer realises it was a typo. saveMatchStats() deletes every
        // stat row of the match and writes it again, emitting nothing a stored
        // ban could have listened for — so the same request must succeed now,
        // with no deploy and nothing to clean up.
        $this->cards($played, [$home['players']['Budi'] => ['yellow' => 1]]);

        $this->save($home, $next, [
            ['player_id' => $home['players']['Budi'], 'role' => 'starter'],
        ])->assertOk();
    }
}
