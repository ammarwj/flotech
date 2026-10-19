<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Cards shown to a team's bench, and everything downstream of one.
 *
 * Every test here **compares** a player with an official, because that is the
 * only shape that can fail honestly. A suite that only ever cards a coach passes
 * against an engine that bans everybody, and one that asserts "the coach is
 * suspended" passes against an engine that lost the players — which is the half
 * of the feature that already worked.
 *
 * The sport is `football` from the seeder, never a synthetic one with
 * hand-written stats: the card keys and the fair-play weights are catalogue data,
 * and the platform_fee_percent bug is what twelve green tests on invented plans
 * cost last time.
 */
class OfficialCardsTest extends TestCase
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
     * @param  array<string, mixed>  $attrs  extra event columns (rules_config…)
     */
    private function event(string $sport = 'football', array $attrs = []): Event
    {
        $event = $this->eventOn($this->org, attrs: [
            'sport_type' => $sport,
            'registration_open' => now()->subDay(),
            'registration_close' => now()->addDays(10),
            // Two-player squads here, so football's real eleven cannot be met.
            // The event override is the third layer of SquadRules doing its job
            // rather than the rule being loosened for the test.
            'rules_config' => ['squad' => ['starters' => 1, 'max_substitutes' => 5], ...$attrs],
        ]);

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
     * A team registered through the public form, with a manager account behind
     * it — that is what sets `teams.manager_user_id`, which the sheet needs.
     *
     * @param  array<int, string>  $players
     * @param  array<int, string>  $officials
     * @return array{manager: User, id: string, players: array<string, string>, officials: array<string, string>}
     */
    private function team(Event $event, string $name, array $players, array $officials = []): array
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
                'officials' => array_map(
                    fn (string $o) => ['full_name' => $o, 'role' => 'head_coach'],
                    $officials,
                ),
            ])
            ->assertCreated()
            ->json('data.team');

        // A fixture can only pair approved teams, so the organizer's verdict is
        // part of the scene rather than a column written directly.
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/v1/organizations/{$this->org->id}/events/{$event->id}/registrations/{$data['id']}", [
                'status' => 'approved',
            ])
            ->assertOk();

        return [
            'manager' => $manager,
            'id' => $data['id'],
            'players' => collect($data['players'])->pluck('id', 'full_name')->all(),
            'officials' => collect($data['officials'] ?? [])->pluck('id', 'full_name')->all(),
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
    private function finish(string $matchId, int $home = 1, int $away = 0): void
    {
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/v1/organizations/{$this->org->id}/matches/{$matchId}", [
                'status' => 'finished', 'home_score' => $home, 'away_score' => $away,
            ])
            ->assertOk();
    }

    /**
     * Save cards through the organizer's own door, the way they are really
     * written — the full-replace semantics are the whole reason suspensions are
     * derived, so a test that wrote rows directly would not be testing this.
     *
     * @param  array<string, array{yellow?: int, red?: int}>  $players
     * @param  array<string, array{yellow?: int, red?: int}>|null  $officials
     */
    private function cards(string $matchId, array $players, ?array $officials = null)
    {
        $payload = ['stats' => $this->statRows($players, 'player_id')];

        if ($officials !== null) {
            $payload['official_stats'] = $this->statRows($officials, 'team_official_id');
        }

        return $this->actingAs($this->owner, 'api')
            ->putJson("/api/v1/organizations/{$this->org->id}/matches/{$matchId}/stats", $payload);
    }

    /**
     * @param  array<string, array<string, int>>  $cards
     * @return array<int, array<string, mixed>>
     */
    private function statRows(array $cards, string $idKey): array
    {
        $rows = [];

        foreach ($cards as $id => $card) {
            foreach (['yellow' => 'yellow_cards', 'red' => 'red_cards', 'goal' => 'goals'] as $slot => $key) {
                if (($card[$slot] ?? 0) > 0) {
                    $rows[] = [$idKey => $id, 'stat_key' => $key, 'value' => $card[$slot]];
                }
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function discipline(Event $event): array
    {
        return $this->actingAs($this->owner, 'api')
            ->getJson("/api/v1/organizations/{$this->org->id}/events/{$event->id}/categories/{$this->categoryId($event)}/discipline")
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function row(array $payload, string $type, string $id): array
    {
        $row = collect($payload['people'])
            ->first(fn (array $r) => $r['person_type'] === $type && $r['person_id'] === $id);

        $this->assertNotNull($row, "{$type} {$id} missing from the discipline payload");

        return $row;
    }

    // ---- the engine ----

    public function test_an_official_accumulates_and_is_banned_exactly_as_a_player_is(): void
    {
        $event = $this->event();
        [$home, $away] = [
            $this->team($event, 'Garuda FC', ['Budi', 'Andi'], ['Pak Coach']),
            $this->team($event, 'Rajawali United', ['Lawan']),
        ];

        // Same category, same fixtures, same request: the only difference is
        // which table the card landed in. A third yellow bans; a second does not.
        foreach ([1, 2, 3] as $n) {
            $matchId = $this->fixture($event, $home['id'], $away['id'], "2026-08-0{$n} 10:00:00");
            $this->finish($matchId);
            $this->cards(
                $matchId,
                [$home['players']['Budi'] => ['yellow' => 1], $home['players']['Andi'] => ['yellow' => $n === 3 ? 0 : 1]],
                [$home['officials']['Pak Coach'] => ['yellow' => 1]],
            )->assertOk();
        }

        $payload = $this->discipline($event);

        $coach = $this->row($payload, 'official', $home['officials']['Pak Coach']);
        $budi = $this->row($payload, 'player', $home['players']['Budi']);
        $andi = $this->row($payload, 'player', $home['players']['Andi']);

        // The comparison that matters: three cards ban whoever carried them, and
        // two ban nobody. Asserting the coach alone would pass against an engine
        // that banned every bench entry it saw.
        $this->assertSame(3, $coach['yellow_total']);
        $this->assertSame(1, $coach['bans_remaining'], 'three yellows did not ban the official');
        $this->assertSame(3, $budi['yellow_total']);
        $this->assertSame(1, $budi['bans_remaining']);
        $this->assertSame(2, $andi['yellow_total']);
        $this->assertSame(0, $andi['bans_remaining'], 'two yellows banned somebody they should not have');

        // And the bench is told apart from the squad rather than merged into it:
        // a coach wears no number, and an empty string there renders as a shirt.
        $this->assertNull($coach['jersey_number']);
        $this->assertSame('head_coach', $coach['role']);
        $this->assertNotNull($budi['jersey_number']);
        $this->assertNull($budi['role']);
    }

    public function test_an_officials_red_card_bans_the_next_fixture_while_a_clean_coach_is_untouched(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi'], ['Pak Coach', 'Pak Manajer']);
        $away = $this->team($event, 'Rajawali United', ['Lawan'], ['Coach Lawan']);

        $first = $this->fixture($event, $home['id'], $away['id'], '2026-08-01 10:00:00');
        $next = $this->fixture($event, $home['id'], $away['id'], '2026-08-02 10:00:00');

        $this->finish($first);
        $this->cards($first, [], [$home['officials']['Pak Coach'] => ['red' => 1]])->assertOk();

        $bans = (array) $this->discipline($event)['matches'];

        $ids = array_column($bans[$next] ?? [], 'person_id');

        // Three comparisons in one list: the carded coach is named, the clean
        // coach of the same bench is not, and neither is the opponent's.
        $this->assertContains($home['officials']['Pak Coach'], $ids);
        $this->assertNotContains($home['officials']['Pak Manajer'], $ids);
        $this->assertNotContains($away['officials']['Coach Lawan'], $ids);

        $entry = collect($bans[$next])->firstWhere('person_id', $home['officials']['Pak Coach']);
        $this->assertSame('red_card', $entry['reason']);
        $this->assertSame('official', $entry['person_type']);
        $this->assertSame($home['id'], $entry['team_id']);

        // The fixture that issued it does not also discharge it — the same
        // serve-before-read ordering the player engine runs on, which is the
        // property a second engine for the bench would have had to re-establish.
        $this->assertArrayNotHasKey($first, $bans);
    }

    public function test_correcting_an_officials_card_downward_removes_the_ban(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi'], ['Pak Coach']);
        $away = $this->team($event, 'Rajawali United', ['Lawan']);

        $first = $this->fixture($event, $home['id'], $away['id'], '2026-08-01 10:00:00');
        $this->fixture($event, $home['id'], $away['id'], '2026-08-02 10:00:00');

        $this->finish($first);
        $this->cards($first, [], [$home['officials']['Pak Coach'] => ['red' => 1]])->assertOk();

        $banned = $this->row($this->discipline($event), 'official', $home['officials']['Pak Coach']);

        // A typo taken back. Nothing about the ban is stored, so it has to go the
        // moment the card does — the property that made suspensions derived in
        // the first place, now with a second table able to break it.
        $this->cards($first, [], [])->assertOk();

        $after = collect($this->discipline($event)['people'])
            ->first(fn (array $r) => $r['person_id'] === $home['officials']['Pak Coach']);

        $this->assertSame(1, $banned['bans_remaining']);
        $this->assertNull($after, 'the official kept a ban after the card behind it was deleted');
    }

    public function test_omitting_the_bench_key_leaves_its_cards_alone_while_an_empty_array_clears_them(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi'], ['Pak Coach']);
        $away = $this->team($event, 'Rajawali United', ['Lawan']);

        $matchId = $this->fixture($event, $home['id'], $away['id'], '2026-08-01 10:00:00');
        $this->finish($matchId);
        $this->cards($matchId, [], [$home['officials']['Pak Coach'] => ['yellow' => 1]])->assertOk();

        // A client that never heard of the bench saves player stats. Its silence
        // must not erase a card somebody else entered.
        $this->cards($matchId, [$home['players']['Budi'] => ['yellow' => 1]], null)->assertOk();

        $this->assertDatabaseCount('official_match_stats', 1);

        // The comparison: an explicit empty array *is* "clear it". Asserting only
        // the first half passes against a build that ignores the key entirely.
        $this->cards($matchId, [$home['players']['Budi'] => ['yellow' => 1]], [])->assertOk();

        $this->assertDatabaseCount('official_match_stats', 0);
        $this->assertDatabaseCount('player_match_stats', 1);
    }

    public function test_the_bench_may_only_be_given_cards(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi'], ['Pak Coach']);
        $away = $this->team($event, 'Rajawali United', ['Lawan']);

        $matchId = $this->fixture($event, $home['id'], $away['id'], '2026-08-01 10:00:00');
        $this->finish($matchId);

        // A coach does not score. Refused rather than dropped, so an editor
        // offering the column is told off instead of saving nothing.
        $this->cards($matchId, [], [$home['officials']['Pak Coach'] => ['goal' => 2]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('official_stats.0.stat_key');

        // The comparison: the same key on a player is accepted, so the refusal
        // above is about who carried it and not about the catalogue.
        $this->cards($matchId, [$home['players']['Budi'] => ['goal' => 1]], [])->assertOk();
    }

    public function test_a_bench_card_cannot_be_written_onto_a_team_that_is_not_playing(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi'], ['Pak Coach']);
        $away = $this->team($event, 'Rajawali United', ['Lawan']);
        $other = $this->team($event, 'Elang FC', ['Orang'], ['Coach Elang']);

        $matchId = $this->fixture($event, $home['id'], $away['id'], '2026-08-01 10:00:00');
        $this->finish($matchId);

        // Dropped, not rejected — a stale tab must not be able to card a bench
        // that is not on the pitch. The accepted half is what proves the drop is
        // about ownership rather than the write failing outright.
        $this->cards($matchId, [], [
            $other['officials']['Coach Elang'] => ['yellow' => 1],
            $home['officials']['Pak Coach'] => ['yellow' => 1],
        ])->assertOk();

        $this->assertDatabaseCount('official_match_stats', 1);
        $this->assertDatabaseHas('official_match_stats', [
            'team_official_id' => $home['officials']['Pak Coach'],
            'team_id' => $home['id'],
        ]);
    }

    public function test_the_leaderboard_never_carries_the_bench(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi'], ['Pak Coach']);
        $away = $this->team($event, 'Rajawali United', ['Lawan']);

        $matchId = $this->fixture($event, $home['id'], $away['id'], '2026-08-01 10:00:00');
        $this->finish($matchId);
        $this->cards(
            $matchId,
            [$home['players']['Budi'] => ['yellow' => 1]],
            [$home['officials']['Pak Coach'] => ['yellow' => 1]],
        )->assertOk();

        $rows = $this->actingAs($this->owner, 'api')
            ->getJson("/api/v1/organizations/{$this->org->id}/events/{$event->id}/categories/{$this->categoryId($event)}/leaderboard")
            ->assertOk()
            ->json('data.rows');

        $names = array_column($rows, 'player_name');

        // Separate tables are what make this structurally true rather than
        // something the leaderboard has to remember to filter — which is the
        // whole argument for not reusing player_match_stats with a nullable
        // owner. Both halves: Budi is there, the coach is not.
        $this->assertContains('Budi', $names);
        $this->assertNotContains('Pak Coach', $names);
    }

    // ---- downstream of a card ----

    public function test_fair_play_counts_a_bench_card_at_the_same_weight_as_a_players(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi'], ['Pak Coach']);
        $away = $this->team($event, 'Rajawali United', ['Lawan'], ['Coach Lawan']);

        $matchId = $this->fixture($event, $home['id'], $away['id'], '2026-08-01 10:00:00');
        $this->finish($matchId, 1, 1);

        // One red each, from opposite tables. Misconduct by the team either way,
        // which is the whole of what this criterion measures.
        $this->cards(
            $matchId,
            [$away['players']['Lawan'] => ['red' => 1]],
            [$home['officials']['Pak Coach'] => ['red' => 1]],
        )->assertOk();

        $rows = collect($this->standings($event))->keyBy(fn (array $row) => $row['team']['name']);

        // Equal, and that equality is the assertion: a build that ignored the
        // bench would give Garuda 0 against Rajawali's 3, and a test asserting
        // "Garuda has fair play points" would pass on 3 alone without ever
        // proving the weight came from the catalogue.
        $this->assertSame(
            $rows['Rajawali United']['fair_play'],
            $rows['Garuda FC']['fair_play'],
            'a red card weighs differently depending on who carried it',
        );
        $this->assertSame(3, $rows['Garuda FC']['fair_play']);
    }

    public function test_the_deposit_deducts_a_bench_card_the_same_way_it_deducts_a_players(): void
    {
        $event = $this->event('football', ['deposit' => [
            'amount' => 1_000_000, 'yellow_deduction' => 50_000, 'red_deduction' => 200_000,
        ]]);
        $home = $this->team($event, 'Garuda FC', ['Budi'], ['Pak Coach']);
        $away = $this->team($event, 'Rajawali United', ['Lawan']);

        $matchId = $this->fixture($event, $home['id'], $away['id'], '2026-08-01 10:00:00');
        $this->finish($matchId);
        $this->cards(
            $matchId,
            [$away['players']['Lawan'] => ['yellow' => 1]],
            [$home['officials']['Pak Coach'] => ['yellow' => 1]],
        )->assertOk();

        $teams = collect(
            $this->actingAs($this->owner, 'api')
                ->getJson("/api/v1/organizations/{$this->org->id}/events/{$event->id}/deposits")
                ->assertOk()
                ->json('data.teams')
        )->keyBy('team_name');

        // Same comparison as fair play: the jaminan answers for everyone a team
        // brings, so one yellow costs the same whichever bench or pitch it came
        // from. Asserting Garuda's balance alone would pass at 1.000.000.
        $this->assertSame(
            $teams['Rajawali United']['balance'],
            $teams['Garuda FC']['balance'],
            'a yellow deducted differently depending on who carried it',
        );
        $this->assertSame(950_000, $teams['Garuda FC']['balance']);
        $this->assertSame(1, $teams['Garuda FC']['yellow_count']);
    }

    public function test_a_suspended_official_is_refused_on_the_bench_while_a_clean_one_is_named(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi'], ['Pak Coach', 'Pak Manajer']);
        $away = $this->team($event, 'Rajawali United', ['Lawan']);

        $first = $this->fixture($event, $home['id'], $away['id'], '2026-08-01 10:00:00');
        $next = $this->fixture($event, $home['id'], $away['id'], '2026-08-02 10:00:00');

        $this->finish($first);
        $this->cards($first, [], [$home['officials']['Pak Coach'] => ['red' => 1]])->assertOk();

        $sheet = fn (array $officials) => $this->actingAs($home['manager'], 'api')
            ->putJson("/api/v1/my-teams/{$home['id']}/matches/{$next}/lineup", [
                'players' => [['player_id' => $home['players']['Budi'], 'role' => 'starter']],
                'officials' => $officials,
            ]);

        // Refused on the `officials` field, not `players`: telling a manager
        // their pelatih cannot *play* sends them hunting through the squad.
        $sheet([['team_official_id' => $home['officials']['Pak Coach']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('officials');

        // The comparison: the clean official on the same bench goes in, so the
        // refusal above is about the ban and not about benches in general.
        $sheet([['team_official_id' => $home['officials']['Pak Manajer']]])->assertOk();

        $this->assertDatabaseHas('match_lineup_officials', [
            'team_official_id' => $home['officials']['Pak Manajer'],
        ]);
        $this->assertDatabaseMissing('match_lineup_officials', [
            'team_official_id' => $home['officials']['Pak Coach'],
        ]);
    }

    public function test_a_draft_bench_that_was_legal_when_saved_is_refused_when_it_is_handed_in(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi'], ['Pak Coach']);
        $away = $this->team($event, 'Rajawali United', ['Lawan']);

        $first = $this->fixture($event, $home['id'], $away['id'], '2026-08-01 10:00:00');
        $next = $this->fixture($event, $home['id'], $away['id'], '2026-08-02 10:00:00');

        // Saved while the coach was clean, which is the point: the second gate in
        // submit() is not a duplicate of the one in sync().
        $this->actingAs($home['manager'], 'api')
            ->putJson("/api/v1/my-teams/{$home['id']}/matches/{$next}/lineup", [
                'players' => [['player_id' => $home['players']['Budi'], 'role' => 'starter']],
                'officials' => [['team_official_id' => $home['officials']['Pak Coach']]],
            ])
            ->assertOk();

        $this->finish($first);
        $this->cards($first, [], [$home['officials']['Pak Coach'] => ['red' => 1]])->assertOk();

        $this->actingAs($home['manager'], 'api')
            ->postJson("/api/v1/my-teams/{$home['id']}/matches/{$next}/lineup/submit")
            ->assertStatus(422)
            ->assertJsonValidationErrors('officials');

        // And the comparison that makes it a gate rather than a wall: the card
        // taken back hands the sheet straight through.
        $this->cards($first, [], [])->assertOk();

        $this->actingAs($home['manager'], 'api')
            ->postJson("/api/v1/my-teams/{$home['id']}/matches/{$next}/lineup/submit")
            ->assertOk();
    }

    public function test_the_editor_payload_carries_the_bench_and_only_the_card_columns(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi'], ['Pak Coach']);
        $away = $this->team($event, 'Rajawali United', ['Lawan'], ['Coach Lawan']);

        $matchId = $this->fixture($event, $home['id'], $away['id'], '2026-08-01 10:00:00');
        $this->finish($matchId);
        $this->cards($matchId, [], [$home['officials']['Pak Coach'] => ['yellow' => 2]])->assertOk();

        $data = $this->actingAs($this->owner, 'api')
            ->getJson("/api/v1/organizations/{$this->org->id}/matches/{$matchId}/stats")
            ->assertOk()
            ->json('data');

        // `card_columns` is sent beside `columns` rather than letting the editor
        // filter the list it already has: the filter is the rule about what the
        // bench may carry, and a second copy of it is a second answer.
        $cards = array_column($data['card_columns'], 'key');
        $all = array_column($data['columns'], 'key');

        $this->assertSame(['yellow_cards', 'red_cards'], $cards);
        $this->assertContains('goals', $all, 'the full column list lost a stat');
        $this->assertNotContains('goals', $cards, 'the bench was offered a column it cannot carry');

        // Both benches are offered, and the tally comes back under the official's
        // own id rather than merged into the player map.
        $this->assertSame('Pak Coach', $data['home_team']['officials'][0]['full_name']);
        $this->assertSame('Coach Lawan', $data['away_team']['officials'][0]['full_name']);
        $this->assertSame(2, $data['official_stats'][$home['officials']['Pak Coach']]['yellow_cards']);
        $this->assertArrayNotHasKey($home['officials']['Pak Coach'], $data['stats']);
    }

    public function test_the_public_match_detail_shows_a_bench_card_beside_the_players(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi'], ['Pak Coach']);
        $away = $this->team($event, 'Rajawali United', ['Lawan']);

        $matchId = $this->fixture($event, $home['id'], $away['id'], '2026-08-01 10:00:00');
        $this->finish($matchId);
        $this->cards(
            $matchId,
            [$home['players']['Budi'] => ['yellow' => 1]],
            [$home['officials']['Pak Coach'] => ['red' => 1]],
        )->assertOk();

        $side = $this->getJson("/api/v1/public/events/{$this->org->slug}/{$event->slug}/matches/{$matchId}/stats")
            ->assertOk()
            ->json('data.home_team');

        // Two lists from two tables rather than one with a type flag: the dialog
        // prints a shirt number beside a player and a jabatan beside a coach.
        // Both halves, so a payload that lost the squad fails too.
        $this->assertSame('Budi', $side['players'][0]['full_name']);
        $this->assertSame('Pak Coach', $side['officials'][0]['full_name']);
        $this->assertSame('head_coach', $side['officials'][0]['role']);
        $this->assertSame(1, $side['officials'][0]['stats']['red_cards']);
        $this->assertArrayNotHasKey('jersey_number', $side['officials'][0]);
    }

    public function test_the_match_report_lists_a_sent_off_coach_under_the_red_cards(): void
    {
        $event = $this->event();
        $home = $this->team($event, 'Garuda FC', ['Budi'], ['Pak Coach']);
        $away = $this->team($event, 'Rajawali United', ['Lawan']);

        $matchId = $this->fixture($event, $home['id'], $away['id'], '2026-08-01 10:00:00');
        $this->finish($matchId);
        $this->cards(
            $matchId,
            [$away['players']['Lawan'] => ['red' => 1]],
            [$home['officials']['Pak Coach'] => ['red' => 1]],
        )->assertOk();

        // The rendered HTML, not the PDF bytes: dompdf output is compressed
        // streams, so asserting on them is the assertion that stays green when a
        // row stops being filled.
        $html = app(\App\Services\MatchReportService::class)
            ->html(\App\Models\GameMatch::findOrFail($matchId));

        $this->assertStringContainsString('Pak Coach', $html);
        // The column heading says what the second column now holds. Printing a
        // jabatan under "NPG" sends the reader looking for a number.
        $this->assertStringContainsString('NPG / Jabatan', $html);
        // The comparison: the player's red is there too, and the scorer list —
        // which the bench must never reach — is still headed for players only.
        $this->assertStringContainsString('Lawan', $html);
        $this->assertStringContainsString('Nama Pemain', $html);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function standings(Event $event): array
    {
        return $this->actingAs($this->owner, 'api')
            ->getJson("/api/v1/organizations/{$this->org->id}/events/{$event->id}/categories/{$this->categoryId($event)}/standings")
            ->assertOk()
            ->json('data');
    }
}
