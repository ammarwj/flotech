<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card accumulation and the bans it produces.
 *
 * Every test here compares two things that must come out different — two players
 * in one category, two events under different rules, a fixture that serves a ban
 * against one that doesn't. Asserting only that "the banned player is banned"
 * passes just as happily against an engine that bans everybody, which is the
 * failure mode this feature would actually have.
 */
class DisciplineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);
        $this->organization = Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $this->user->id, 'plan_id' => $plan->id,
        ]);
    }

    // ---- fixtures ----

    /**
     * @param  array<string, mixed>  $attributes  extra event columns (rules_config…)
     */
    private function event(string $sport = 'mini_soccer', string $format = 'league', array $attributes = []): Event
    {
        $event = $this->organization->events()->create([
            'name' => 'Discipline Cup',
            'slug' => 'discipline-cup-'.uniqid(),
            'sport_type' => $sport,
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
            ...$attributes,
        ]);

        $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => $format,
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
     * Register a team through the endpoint the organizer uses.
     *
     * @param  array<int, string>  $players
     * @return array{0: string, 1: array<string, string>} team id, player name => id
     */
    private function team(Event $event, string $name, array $players = []): array
    {
        $data = $this->actingAs($this->user, 'api')
            ->postJson("/api/v1/organizations/{$this->organization->id}/events/{$event->id}/registrations", [
                'category_id' => $this->categoryId($event),
                'name' => $name,
                'players' => array_map(
                    fn (string $p, int $i) => ['full_name' => $p, 'jersey_number' => (string) ($i + 1)],
                    $players,
                    array_keys($players),
                ),
            ])
            ->assertCreated()
            ->json('data');

        return [$data['id'], collect($data['players'])->pluck('id', 'full_name')->all()];
    }

    private function fixture(Event $event, string $home, string $away, ?string $kickoff = null): string
    {
        return $this->actingAs($this->user, 'api')
            ->postJson("/api/v1/organizations/{$this->organization->id}/events/{$event->id}/categories/{$this->categoryId($event)}/matches", [
                'home_team_id' => $home,
                'away_team_id' => $away,
                'scheduled_at' => $kickoff,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * Finish a fixture. The owner administers the org, so updateResult signs it
     * off in the same call — which is what makes the cards on it count.
     */
    private function finish(string $matchId, int $home = 1, int $away = 0): void
    {
        $this->actingAs($this->user, 'api')
            ->patchJson("/api/v1/organizations/{$this->organization->id}/matches/{$matchId}", [
                'status' => 'finished', 'home_score' => $home, 'away_score' => $away,
            ])
            ->assertOk();
    }

    /**
     * @param  array<string, array{yellow?: int, red?: int}>  $cards  player id => cards
     */
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

        $this->actingAs($this->user, 'api')
            ->putJson("/api/v1/organizations/{$this->organization->id}/matches/{$matchId}/stats", ['stats' => $stats])
            ->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function discipline(Event $event): array
    {
        return $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/organizations/{$this->organization->id}/events/{$event->id}/categories/{$this->categoryId($event)}/discipline")
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function playerRow(array $payload, string $playerId): array
    {
        $row = collect($payload['players'])->firstWhere('player_id', $playerId);

        $this->assertNotNull($row, 'player missing from the discipline payload');

        return $row;
    }

    // ---- tests ----

    public function test_a_third_yellow_bans_while_a_second_yellow_does_not(): void
    {
        $event = $this->event();
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi', 'Andi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        // Same category, same fixtures, same request: only the card count differs.
        foreach ([1, 2, 3] as $n) {
            $matchId = $this->fixture($event, $home, $away, "2026-08-0{$n} 10:00:00");
            $this->finish($matchId);
            $this->cards($matchId, $n === 3
                ? [$players['Budi'] => ['yellow' => 1]]
                : [$players['Budi'] => ['yellow' => 1], $players['Andi'] => ['yellow' => 1]]);
        }

        $payload = $this->discipline($event);
        $budi = $this->playerRow($payload, $players['Budi']);
        $andi = $this->playerRow($payload, $players['Andi']);

        $this->assertSame(3, $budi['yellow_total']);
        $this->assertSame(1, $budi['bans_remaining'], 'three yellows did not produce a ban');
        $this->assertSame(0, $budi['yellow_running'], 'the counter did not reset when the ban was issued');

        $this->assertSame(2, $andi['yellow_total']);
        $this->assertSame(0, $andi['bans_remaining'], 'two yellows banned a player they should not have');
        $this->assertSame(2, $andi['yellow_running']);
    }

    public function test_the_match_that_issues_a_ban_does_not_also_serve_it(): void
    {
        $event = $this->event();
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        $third = null;

        foreach ([1, 2, 3] as $n) {
            $third = $this->fixture($event, $home, $away, "2026-08-0{$n} 10:00:00");
            $this->finish($third);
            $this->cards($third, [$players['Budi'] => ['yellow' => 1]]);
        }

        $payload = $this->discipline($event);

        // Serving before reading is what keeps this true. Reversed, the ban is
        // discharged by the very match that produced it and nothing is ever
        // reported — the feature would look like it works and warn nobody.
        $this->assertSame(1, $this->playerRow($payload, $players['Budi'])['bans_remaining']);
        $this->assertArrayNotHasKey($third, (array) $payload['matches'], 'the banning fixture served its own ban');
    }

    public function test_the_next_official_match_serves_the_ban(): void
    {
        $event = $this->event();
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi', 'Andi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        foreach ([1, 2, 3] as $n) {
            $matchId = $this->fixture($event, $home, $away, "2026-08-0{$n} 10:00:00");
            $this->finish($matchId);
            $this->cards($matchId, [$players['Budi'] => ['yellow' => 1]]);
        }

        // Andi is sent off in the third match, so he is still owed a match when
        // the fourth is played — the control that proves the fourth discharged
        // one specific ban rather than clearing the board.
        $this->cards($matchId, [$players['Budi'] => ['yellow' => 1], $players['Andi'] => ['red' => 1]]);

        $before = $this->discipline($event);
        $this->assertSame(1, $this->playerRow($before, $players['Budi'])['bans_remaining']);
        $this->assertSame(1, $this->playerRow($before, $players['Andi'])['bans_remaining']);

        $fourth = $this->fixture($event, $home, $away, '2026-08-04 10:00:00');
        $this->finish($fourth);

        $after = $this->discipline($event);
        $budi = $this->playerRow($after, $players['Budi']);

        $this->assertSame(0, $budi['bans_remaining'], 'the ban was never served');
        $this->assertSame(1, $budi['bans_served']);
        $this->assertSame(0, $this->playerRow($after, $players['Andi'])['bans_remaining']);
    }

    public function test_only_a_confirmed_result_issues_cards(): void
    {
        $event = $this->event();
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi', 'Andi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        // Two identical matches, three yellows each, for two different players.
        foreach ([['Budi', 1], ['Andi', 2]] as [$name, $n]) {
            $matchId = $this->fixture($event, $home, $away, "2026-08-0{$n} 10:00:00");
            $this->finish($matchId);
            $this->cards($matchId, [$players[$name] => ['yellow' => 3]]);

            // Andi's match is finished but never signed off — the state an org
            // with an operator lives in until an admin ratifies.
            if ($name === 'Andi') {
                GameMatch::where('id', $matchId)->update(['confirmed_at' => null]);
            }
        }

        $payload = $this->discipline($event);

        $this->assertSame(1, $this->playerRow($payload, $players['Budi'])['bans_remaining']);
        $this->assertNull(
            collect($payload['players'])->firstWhere('player_id', $players['Andi']),
            'an unconfirmed result issued a ban',
        );
    }

    public function test_a_cancelled_fixture_neither_carries_a_warning_nor_serves_the_ban(): void
    {
        $event = $this->event();
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi']);
        [$away, $awayPlayers] = $this->team($event, 'Rajawali United', ['Cakra']);

        // Both sides pick up the same ban in the same match.
        $first = $this->fixture($event, $home, $away, '2026-08-01 10:00:00');
        $this->finish($first);
        $this->cards($first, [$players['Budi'] => ['red' => 1], $awayPlayers['Cakra'] => ['red' => 1]]);

        $cancelled = $this->fixture($event, $home, $away, '2026-08-02 10:00:00');
        $later = $this->fixture($event, $home, $away, '2026-08-03 10:00:00');

        $this->actingAs($this->user, 'api')
            ->patchJson("/api/v1/organizations/{$this->organization->id}/matches/{$cancelled}/status", ['status' => 'cancelled'])
            ->assertOk();

        $bans = (array) $this->discipline($event)['matches'];

        $this->assertArrayNotHasKey($cancelled, $bans, 'a cancelled fixture was warned about');
        $this->assertArrayHasKey($later, $bans, 'the ban did not carry past the cancelled fixture');
        $this->assertCount(2, $bans[$later], 'both suspended players should land on the same fixture');
    }

    public function test_a_group_stage_ban_still_applies_in_the_knockout(): void
    {
        $event = $this->event('mini_soccer', 'hybrid');
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        $group = $this->fixture($event, $home, $away, '2026-08-01 10:00:00');
        $this->finish($group);
        $this->cards($group, [$players['Budi'] => ['red' => 1]]);
        GameMatch::where('id', $group)->update(['stage' => 'group']);

        $knockout = $this->fixture($event, $home, $away, '2026-08-10 10:00:00');
        GameMatch::where('id', $knockout)->update(['stage' => 'knockout']);

        // fairPlayPoints() filters the knockout out because it feeds a standings
        // criterion. A ban is not a criterion — it follows the player.
        $this->assertArrayHasKey(
            $knockout,
            (array) $this->discipline($event)['matches'],
            'a ban earned in the group stage was dropped at the bracket',
        );
    }

    public function test_a_red_card_bans_immediately_while_a_single_yellow_does_not(): void
    {
        $event = $this->event();
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi', 'Andi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        $matchId = $this->fixture($event, $home, $away, '2026-08-01 10:00:00');
        $this->finish($matchId);
        $this->cards($matchId, [
            $players['Budi'] => ['red' => 1],
            $players['Andi'] => ['yellow' => 1],
        ]);

        $payload = $this->discipline($event);

        $this->assertSame(1, $this->playerRow($payload, $players['Budi'])['bans_remaining']);
        $this->assertSame(0, $this->playerRow($payload, $players['Andi'])['bans_remaining']);
    }

    public function test_four_yellows_in_a_single_match_still_cross_a_threshold_of_two(): void
    {
        // The tally is an aggregate, so an organizer can type a number that
        // crosses the threshold more than once at a time. An `if` instead of a
        // loop would issue one ban here and quietly lose the rest.
        //
        // The sending-off rule is switched off on purpose: left on it would swallow
        // the first two yellows and this would pass while testing nothing about
        // the accumulation loop it is named after.
        $event = $this->event('mini_soccer', 'league', [
            'rules_config' => ['discipline' => ['yellow_threshold' => 2, 'yellows_per_expulsion' => 0]],
        ]);
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        $matchId = $this->fixture($event, $home, $away, '2026-08-01 10:00:00');
        $this->finish($matchId);
        $this->cards($matchId, [$players['Budi'] => ['yellow' => 4]]);

        $row = $this->playerRow($this->discipline($event), $players['Budi']);

        $this->assertSame(2, $row['bans_remaining'], 'four yellows at a threshold of two owe two matches');
        $this->assertSame(0, $row['yellow_running']);
    }

    public function test_an_event_rule_overrides_the_sport_default(): void
    {
        // Identical fixtures under two rulebooks. Asserting the column was saved
        // would pass even if the service never read it.
        $lenient = $this->event();
        $strict = $this->event('mini_soccer', 'league', [
            'rules_config' => ['discipline' => ['yellow_threshold' => 2]],
        ]);

        $remaining = [];

        foreach (['lenient' => $lenient, 'strict' => $strict] as $label => $event) {
            [$home, $players] = $this->team($event, 'Garuda FC', ['Budi']);
            [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

            foreach ([1, 2] as $n) {
                $matchId = $this->fixture($event, $home, $away, "2026-08-0{$n} 10:00:00");
                $this->finish($matchId);
                $this->cards($matchId, [$players['Budi'] => ['yellow' => 1]]);
            }

            $remaining[$label] = $this->playerRow($this->discipline($event), $players['Budi'])['bans_remaining'];
        }

        $this->assertSame(0, $remaining['lenient'], 'the sport default of three was not applied');
        $this->assertSame(1, $remaining['strict'], 'the event override of two was ignored');
    }

    public function test_saving_discipline_rules_keeps_the_other_rules_config_namespaces(): void
    {
        $event = $this->event('mini_soccer', 'league', [
            'rules_config' => ['other' => ['x' => 1]],
        ]);

        $this->actingAs($this->user, 'api')
            ->putJson("/api/v1/organizations/{$this->organization->id}/events/{$event->id}", [
                'rules_config' => ['discipline' => ['yellow_threshold' => 2]],
            ])
            ->assertOk();

        $stored = $event->fresh()->rules_config;

        $this->assertSame(['x' => 1], $stored['other'] ?? null, 'a neighbouring rulebook was wiped');
        $this->assertSame(2, $stored['discipline']['yellow_threshold']);
    }

    public function test_a_sport_without_card_stats_returns_a_disabled_payload(): void
    {
        // Volleyball is the one that matters: it fields squads and shows player
        // stats, so a gate copied from showsPlayerStats() would switch this on
        // for a sport that has no card column at all.
        foreach (['badminton', 'volleyball'] as $sport) {
            $event = $this->event($sport);
            $payload = $this->discipline($event);

            $this->assertFalse($payload['enabled'], "{$sport} reported card tracking it doesn't have");
            $this->assertNull($payload['rules']);
            $this->assertSame([], $payload['players']);
        }

        // The map is indexed by match id, so it has to serialize as {} — an
        // empty PHP array would arrive as [].
        $raw = $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/organizations/{$this->organization->id}/events/{$event->id}/categories/{$this->categoryId($event)}/discipline")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"matches":{}', $raw);
    }

    public function test_rewriting_the_same_stats_does_not_change_the_verdict(): void
    {
        // saveMatchStats deletes and recreates every row, so nothing derived
        // from those rows may depend on their identity or age. This is the test
        // that says the derived design was the right call.
        $event = $this->event();
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        $last = null;

        foreach ([1, 2, 3] as $n) {
            $last = $this->fixture($event, $home, $away, "2026-08-0{$n} 10:00:00");
            $this->finish($last);
            $this->cards($last, [$players['Budi'] => ['yellow' => 1]]);
        }

        $before = $this->discipline($event);
        $this->cards($last, [$players['Budi'] => ['yellow' => 1]]);

        $this->assertEquals($before, $this->discipline($event), 'an identical rewrite moved the verdict');
    }

    public function test_correcting_a_card_downward_removes_the_ban(): void
    {
        $event = $this->event();
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        $last = null;

        foreach ([1, 2, 3] as $n) {
            $last = $this->fixture($event, $home, $away, "2026-08-0{$n} 10:00:00");
            $this->finish($last);
            $this->cards($last, [$players['Budi'] => ['yellow' => 1]]);
        }

        $this->assertSame(1, $this->playerRow($this->discipline($event), $players['Budi'])['bans_remaining']);

        // The organizer notices the third yellow was a typo. A stored ban would
        // have no event to listen for here and would outlive its cause.
        $this->cards($last, []);

        $row = $this->playerRow($this->discipline($event), $players['Budi']);

        $this->assertSame(0, $row['bans_remaining'], 'the ban survived the card being removed');
        $this->assertSame(2, $row['yellow_running']);
    }

    public function test_an_undated_fixture_is_warned_after_a_dated_one(): void
    {
        $event = $this->event();
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        $played = $this->fixture($event, $home, $away, '2026-08-01 10:00:00');
        $this->finish($played);
        $this->cards($played, [$players['Budi'] => ['red' => 1]]);

        // Created undated first, so insertion order would pick it if the sort
        // key didn't push undated fixtures to the back.
        $undated = $this->fixture($event, $home, $away);
        $dated = $this->fixture($event, $home, $away, '2026-08-05 10:00:00');

        $bans = (array) $this->discipline($event)['matches'];

        $this->assertArrayHasKey($dated, $bans, 'the ban skipped the next scheduled fixture');
        $this->assertArrayNotHasKey($undated, $bans, 'an unscheduled fixture absorbed the ban');
    }

    public function test_a_fixture_awaiting_its_opponent_still_carries_the_warning(): void
    {
        // The two halves of the bye rule pull apart here, which is why they are
        // two tests: a fixture with one side missing must not *serve* a ban, but
        // a bracket slot the team is already seated in must still *warn* about
        // one. Requiring both sides for warnings hides the suspension until the
        // draw runs — the latest possible moment to find out.
        $event = $this->event('mini_soccer', 'hybrid');
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        $group = $this->fixture($event, $home, $away, '2026-08-01 10:00:00');
        $this->finish($group);
        $this->cards($group, [$players['Budi'] => ['red' => 1]]);

        // A semifinal slot: Garuda are in it, their opponent isn't decided yet.
        $slot = $this->fixture($event, $home, $away, '2026-08-10 10:00:00');
        GameMatch::where('id', $slot)->update(['away_team_id' => null, 'stage' => 'knockout']);

        $this->assertArrayHasKey(
            $slot,
            (array) $this->discipline($event)['matches'],
            'a bracket slot waiting on its opponent hid the suspension',
        );
    }

    public function test_a_bye_neither_issues_nor_serves_a_ban(): void
    {
        $event = $this->event();
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        $first = $this->fixture($event, $home, $away, '2026-08-01 10:00:00');
        $this->finish($first);
        $this->cards($first, [$players['Budi'] => ['red' => 1]]);

        // A walkover: one side present, already settled. Nobody sat on a bench.
        $bye = $this->fixture($event, $home, $away, '2026-08-02 10:00:00');
        GameMatch::where('id', $bye)->update([
            'away_team_id' => null, 'status' => 'finished', 'home_score' => 3, 'away_score' => 0, 'confirmed_at' => now(),
        ]);

        $later = $this->fixture($event, $home, $away, '2026-08-03 10:00:00');
        $payload = $this->discipline($event);

        $this->assertSame(1, $this->playerRow($payload, $players['Budi'])['bans_remaining'], 'a bye served the ban');
        $this->assertArrayHasKey($later, (array) $payload['matches']);
    }

    // ---- sending-off: two yellows inside one match ----

    public function test_two_yellows_in_one_match_expel_while_two_across_matches_do_not(): void
    {
        // The whole rule in one comparison: the same two cards, told apart only
        // by whether they landed in the same fixture. Asserting the expelled
        // player is banned would pass just as well against an engine that
        // dropped the threshold to two.
        $event = $this->event();
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi', 'Andi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        // The double yellow lands in the *last* fixture on purpose: an earlier
        // one would be served by the fixtures after it, and bans_remaining
        // would read 0 for a ban that was correctly issued.
        foreach ([1, 2] as $n) {
            $matchId = $this->fixture($event, $home, $away, "2026-08-0{$n} 10:00:00");
            $this->finish($matchId);
            $this->cards($matchId, $n === 2
                ? [$players['Budi'] => ['yellow' => 2], $players['Andi'] => ['yellow' => 1]]
                : [$players['Andi'] => ['yellow' => 1]]);
        }

        $payload = $this->discipline($event);
        $budi = $this->playerRow($payload, $players['Budi']);
        $andi = $this->playerRow($payload, $players['Andi']);

        $this->assertSame(2, $budi['yellow_total']);
        $this->assertSame(1, $budi['bans_remaining'], 'two yellows in one match did not expel');

        $this->assertSame(2, $andi['yellow_total']);
        $this->assertSame(0, $andi['bans_remaining'], 'two yellows across two matches expelled anyway');
        $this->assertSame(2, $andi['yellow_running']);
    }

    public function test_the_yellows_that_expel_do_not_also_accumulate(): void
    {
        // Four yellows each, four fixtures each, one ban each — but they arrive
        // differently, and the counter left behind is what tells the two apart.
        // Letting the expelling pair through to the tally would punish the one
        // dismissal twice: Budi would end on a second, accumulated ban.
        $event = $this->event();
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi', 'Andi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        foreach ([1, 2, 3, 4] as $n) {
            $matchId = $this->fixture($event, $home, $away, "2026-08-0{$n} 10:00:00");
            $this->finish($matchId);
            $this->cards($matchId, [
                // 2 + 1 + 1 + 0: a sending-off, then two loose cautions.
                ...($n <= 3 ? [$players['Budi'] => ['yellow' => $n === 1 ? 2 : 1]] : []),
                // 1 + 1 + 1 + 1: a plain accumulation, ban on the third.
                $players['Andi'] => ['yellow' => 1],
            ]);
        }

        $payload = $this->discipline($event);
        $budi = $this->playerRow($payload, $players['Budi']);
        $andi = $this->playerRow($payload, $players['Andi']);

        $this->assertSame(4, $budi['yellow_total']);
        $this->assertSame(1, $budi['bans_issued'], 'the expelling yellows were counted towards the tally too');
        $this->assertSame(2, $budi['yellow_running'], 'the cautions after the dismissal were lost');

        $this->assertSame(4, $andi['yellow_total']);
        $this->assertSame(1, $andi['bans_issued']);
        $this->assertSame(1, $andi['yellow_running'], 'the counter did not reset when the ban was issued');
    }

    public function test_a_red_card_absorbs_the_expulsion_from_the_same_match(): void
    {
        // An organizer recording a second-yellow dismissal usually types both
        // the cautions and the red. Both sides are asserted on purpose: only
        // comparing them shows the absorption happened rather than the rule
        // never firing.
        $event = $this->event();
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi', 'Andi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        $matchId = $this->fixture($event, $home, $away, '2026-08-01 10:00:00');
        $this->finish($matchId);
        $this->cards($matchId, [
            $players['Budi'] => ['yellow' => 2, 'red' => 1],
            $players['Andi'] => ['red' => 1],
        ]);

        $payload = $this->discipline($event);
        $budi = $this->playerRow($payload, $players['Budi']);

        $this->assertSame(
            $this->playerRow($payload, $players['Andi'])['bans_remaining'],
            $budi['bans_remaining'],
            'one incident recorded twice was punished twice',
        );
        $this->assertSame(1, $budi['bans_remaining']);
        // The absorbed cautions are still history, they just bought nothing.
        $this->assertSame(2, $budi['yellow_total']);
        $this->assertSame(0, $budi['yellow_running']);
    }

    public function test_the_expulsion_rule_can_be_switched_off_per_event(): void
    {
        // Identical cards under two rulebooks — the same shape as the threshold
        // override test, because 0 has to mean "off" rather than "unset".
        $lenient = $this->event('mini_soccer', 'league', [
            'rules_config' => ['discipline' => ['yellows_per_expulsion' => 0]],
        ]);
        $strict = $this->event();

        $remaining = [];

        foreach (['lenient' => $lenient, 'strict' => $strict] as $label => $event) {
            [$home, $players] = $this->team($event, 'Garuda FC', ['Budi']);
            [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

            $matchId = $this->fixture($event, $home, $away, '2026-08-01 10:00:00');
            $this->finish($matchId);
            $this->cards($matchId, [$players['Budi'] => ['yellow' => 2]]);

            $remaining[$label] = $this->playerRow($this->discipline($event), $players['Budi'])['bans_remaining'];
        }

        $this->assertSame(0, $remaining['lenient'], 'a zero did not switch the sending-off rule off');
        $this->assertSame(1, $remaining['strict'], 'the platform default of two was not applied');
    }

    public function test_an_empty_event_field_inherits_the_sport_default_rather_than_zeroing_it(): void
    {
        // An organizer who only edits the threshold leaves the other fields
        // blank, and blank must not read as zero anywhere along the way. The
        // explicit-zero event beside it is what proves clean() is dropping the
        // absent keys rather than the merge ignoring the layer entirely.
        $partial = $this->event('mini_soccer', 'league', [
            'rules_config' => ['discipline' => ['yellow_threshold' => 2]],
        ]);
        $explicit = $this->event('mini_soccer', 'league', [
            'rules_config' => ['discipline' => ['yellows_per_expulsion' => 0, 'expulsion_ban_matches' => 0]],
        ]);

        $inherited = $this->discipline($partial)['rules'];
        $zeroed = $this->discipline($explicit)['rules'];

        $this->assertSame(2, $inherited['yellow_threshold'], 'the field the organizer did set was lost');
        $this->assertSame(2, $inherited['yellows_per_expulsion'], 'a blank field zeroed the sport default');
        $this->assertSame(1, $inherited['expulsion_ban_matches'], 'a blank field zeroed the sport default');

        $this->assertSame(0, $zeroed['yellows_per_expulsion']);
        $this->assertSame(0, $zeroed['expulsion_ban_matches']);
    }

    public function test_an_expulsion_ban_survives_the_knockout_reset(): void
    {
        // The reset wipes running yellows, never an outstanding ban. Budi's is
        // outstanding when the bracket starts; Andi's two cautions are not.
        $event = $this->event('mini_soccer', 'hybrid', [
            'rules_config' => ['discipline' => ['reset_yellow_on_knockout' => true]],
        ]);
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi', 'Andi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        $group = $this->fixture($event, $home, $away, '2026-08-01 10:00:00');
        $this->finish($group);
        $this->cards($group, [
            $players['Budi'] => ['yellow' => 2],
            $players['Andi'] => ['yellow' => 2],
        ]);
        GameMatch::where('id', $group)->update(['stage' => 'group']);

        $semi = $this->fixture($event, $home, $away, '2026-08-10 10:00:00');
        GameMatch::where('id', $semi)->update(['stage' => 'knockout']);

        $payload = $this->discipline($event);

        $this->assertSame(
            1,
            $this->playerRow($payload, $players['Budi'])['bans_remaining'],
            'the knockout reset discharged a ban it should only have cleared cautions for',
        );
        $this->assertContains(
            $players['Budi'],
            array_column(((array) $payload['matches'])[$semi] ?? [], 'player_id'),
            'the bracket fixture lost the warning',
        );
    }

    public function test_the_reason_is_reported_as_second_yellow(): void
    {
        // Three players, three reasons, one fixture to read them off. The reason
        // is what the public card prints, so a sending-off reported as an
        // accumulation would tell the crowd the wrong story.
        $event = $this->event();
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi', 'Andi', 'Citra']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        foreach ([1, 2, 3] as $n) {
            $matchId = $this->fixture($event, $home, $away, "2026-08-0{$n} 10:00:00");
            $this->finish($matchId);
            $this->cards($matchId, $n === 3
                ? [
                    $players['Budi'] => ['yellow' => 2],
                    $players['Andi'] => ['red' => 1],
                    $players['Citra'] => ['yellow' => 1],
                ]
                : [$players['Citra'] => ['yellow' => 1]]);
        }

        $next = $this->fixture($event, $home, $away, '2026-08-09 10:00:00');
        $reasons = collect(((array) $this->discipline($event)['matches'])[$next] ?? [])
            ->pluck('reason', 'player_id');

        $this->assertSame('second_yellow', $reasons[$players['Budi']] ?? null);
        $this->assertSame('red_card', $reasons[$players['Andi']] ?? null);
        $this->assertSame('yellow_accumulation', $reasons[$players['Citra']] ?? null);
    }

    public function test_the_public_endpoint_reports_the_same_bans_without_a_login(): void
    {
        // Two readers of one fact. Asserting the public route answers 200 would
        // pass against an endpoint returning an empty payload, so the organizer
        // view is fetched beside it and the two are compared whole.
        $event = $this->event();
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        $matchId = $this->fixture($event, $home, $away, '2026-08-01 10:00:00');
        $this->finish($matchId);
        $this->cards($matchId, [$players['Budi'] => ['yellow' => 2]]);
        $next = $this->fixture($event, $home, $away, '2026-08-02 10:00:00');

        $category = $event->categories->first();

        // No actingAs: this is what an anonymous visitor gets.
        $public = $this->getJson("/api/v1/public/events/{$this->organization->slug}/{$event->slug}/categories/{$category->slug}/discipline")
            ->assertOk()
            ->json('data');

        $this->assertEquals($this->discipline($event), $public, 'the public tally drifted from the organizer one');
        $this->assertArrayHasKey($next, (array) $public['matches']);
        $this->assertSame($players['Budi'], ((array) $public['matches'])[$next][0]['player_id']);
    }
}
