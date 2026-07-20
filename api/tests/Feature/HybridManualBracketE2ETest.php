<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The whole hybrid tournament, driven the way the organizer drives it: create
 * the event, enter the teams, play the groups out, seed the bracket by hand,
 * and play it down to a champion and a third place.
 *
 * Every step goes over HTTP. The other hybrid tests build their fixtures with
 * models and call the schedule service directly, which is faster but skips the
 * request validation, the tenancy middleware and the result/confirm split —
 * exactly the seams where the flow has broken before. This one exists to catch
 * a break that only shows up when the steps are strung together: standings that
 * are right on their own but reach the seed editor in the wrong order, a bracket
 * that generates but whose losers never reach the third-place tie.
 *
 * Group results are deliberately not hardcoded. The draw is random, so the test
 * reads back who actually qualified and asserts the *relationships* — top two of
 * each group, semifinal losers meeting for third — rather than a fixed cast.
 */
class HybridManualBracketE2ETest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    private string $eventId;

    private string $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);
        foreach (['max_active_events' => '5', 'max_teams_per_event' => '32'] as $key => $value) {
            $plan->features()->create(['feature_key' => $key, 'value' => $value]);
        }

        $this->org = Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $this->user->id, 'plan_id' => $plan->id,
        ]);
    }

    private function orgUrl(string $path = ''): string
    {
        return "/api/v1/organizations/{$this->org->id}".$path;
    }

    private function categoryUrl(string $path): string
    {
        return $this->orgUrl("/events/{$this->eventId}/categories/{$this->categoryId}".$path);
    }

    /** Step 1: create the event with a hybrid category that plays for third. */
    private function createEvent(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson($this->orgUrl('/events'), [
                'name' => 'Piala Nusantara 2026',
                'sport_type' => 'football',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-30',
                'timezone' => 'Asia/Jakarta',
                'categories' => [[
                    'name' => 'Umum',
                    'tournament_format' => 'hybrid',
                    'registration_fee' => 0,
                    'bracket_config' => [
                        'groups' => 2,
                        'teams_per_group' => 4,
                        'home_away' => false,
                        'points' => ['win' => 3, 'draw' => 1, 'lose' => 0],
                        'qualification' => ['top_per_group' => 2],
                        'draw_method' => 'random',
                        'third_place' => true,
                    ],
                ]],
            ])
            ->assertCreated();

        $this->eventId = $response->json('data.id');
        $this->categoryId = $response->json('data.categories.0.id');

        $this->assertNotNull($this->categoryId, 'the category must come back with the event');
    }

    /** Step 2: enter eight teams by hand, as an organizer taking offline entries. */
    private function registerTeams(int $count = 8): void
    {
        foreach (range(1, $count) as $i) {
            $this->actingAs($this->user, 'api')
                ->postJson($this->orgUrl("/events/{$this->eventId}/registrations"), [
                    'category_id' => $this->categoryId,
                    'name' => 'Klub '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                    'contact_name' => 'Manajer '.$i,
                    'contact_phone' => '08120000'.$i,
                ])
                ->assertCreated();
        }
    }

    /** Play a fixture and sign the result off — two calls, as the UI does. */
    private function playAndConfirm(string $matchId, int $home, int $away): void
    {
        $this->actingAs($this->user, 'api')
            ->patchJson($this->orgUrl("/matches/{$matchId}"), [
                'status' => 'finished',
                'home_score' => $home,
                'away_score' => $away,
            ])
            ->assertOk();

        $this->actingAs($this->user, 'api')
            ->patchJson($this->orgUrl("/matches/{$matchId}/confirm"), ['confirmed' => true])
            ->assertOk();
    }

    /** @return array<int, array<string, mixed>> the category's fixtures, via the API */
    private function fixtures(): array
    {
        return $this->actingAs($this->user, 'api')
            ->getJson($this->categoryUrl('/matches'))
            ->assertOk()
            ->json('data');
    }

    /** @return array<int, array<string, mixed>> */
    private function knockoutRound(int $round, ?string $bracket = null): array
    {
        return array_values(array_filter(
            $this->fixtures(),
            fn ($m) => $m['stage'] === 'knockout'
                && $m['round'] === $round
                && ($m['bracket'] ?? null) === $bracket,
        ));
    }

    public function test_hybrid_event_from_creation_through_manual_bracket_to_third_place(): void
    {
        $this->createEvent();
        $this->registerTeams();

        // --- Group stage ------------------------------------------------------

        $this->actingAs($this->user, 'api')
            ->postJson($this->categoryUrl('/schedule'), [
                'start_date' => '2026-09-01',
                'daily_start' => '08:00',
                'daily_end' => '21:00',
                'venues' => 2,
            ])
            ->assertCreated();

        // Two groups of four, single round robin: 6 fixtures each.
        $group = array_values(array_filter($this->fixtures(), fn ($m) => $m['stage'] === 'group'));
        $this->assertCount(12, $group);

        // The bracket is blocked until every group game is signed off, so the
        // plan must say so before a single result exists.
        $this->assertSame(
            12,
            $this->actingAs($this->user, 'api')
                ->getJson($this->categoryUrl('/knockout-plan'))->assertOk()
                ->json('data.group_matches_pending'),
        );

        $this->actingAs($this->user, 'api')
            ->postJson($this->categoryUrl('/knockout'), ['seeding' => 'auto'])
            ->assertStatus(422)
            ->assertJsonPath('errors.feature', 'group_stage_incomplete');

        // Play them all. Home wins every time, so each group ends up strictly
        // ordered by how often a team was drawn at home — no ties to break.
        foreach ($group as $m) {
            $this->playAndConfirm($m['id'], 3, 1);
        }

        // --- Standings --------------------------------------------------------

        $standings = $this->actingAs($this->user, 'api')
            ->getJson($this->categoryUrl('/standings'))
            ->assertOk()
            ->json('data');

        $this->assertCount(8, $standings, 'every team belongs to exactly one group table');

        foreach ($standings as $row) {
            $this->assertSame(3, $row['played']);
            $this->assertSame($row['won'] * 3, $row['points'], 'no draws were played');
        }

        // Ranked within each group, groups concatenated A then B. Reading the
        // table in the wrong order is how the wrong teams reach the bracket, so
        // the ordering is asserted, not just the contents.
        foreach (collect($standings)->groupBy('group_name') as $groupName => $groupRows) {
            $this->assertNotNull($groupName);
            $this->assertSame([1, 2, 3, 4], $groupRows->pluck('rank')->all());
            $this->assertSame(
                $groupRows->pluck('points')->sortDesc()->values()->all(),
                $groupRows->pluck('points')->all(),
                "grup {$groupName} harus urut poin menurun",
            );
        }

        // --- The bracket the organizer draws by hand --------------------------

        $plan = $this->actingAs($this->user, 'api')
            ->getJson($this->categoryUrl('/knockout-plan'))
            ->assertOk()
            ->json('data');

        $this->assertSame(12, $plan['group_matches_total']);
        $this->assertSame(0, $plan['group_matches_pending']);
        $this->assertSame(4, $plan['bracket_size']);
        $this->assertSame(4, $plan['qualifiers']);

        // Keyed by the slot they hold, not by position in the tie list: the
        // plan is already seeded, so reading it positionally and posting it
        // back would reproduce the automatic draw and prove nothing.
        $held = collect($plan['ties'])
            ->flatMap(fn ($tie) => [$tie['home'], $tie['away']])
            ->filter(fn ($slot) => $slot !== null && $slot['team'] !== null)
            ->mapWithKeys(fn ($slot) => [$slot['label'] => $slot['team']['id']]);

        $this->assertCount(4, $held);

        $juaraA = $held['Juara Grup A'];
        $juaraB = $held['Juara Grup B'];
        $runnerA = $held['Runner-up Grup A'];
        $runnerB = $held['Runner-up Grup B'];

        // Automatic seeding always crosses the groups: a winner meets the other
        // group's runner-up. Putting the two group winners in the same tie is a
        // draw it cannot produce, so a bracket that comes back this way can only
        // have honoured the payload.
        $this->assertEqualsCanonicalizing(
            [[$juaraA, $runnerB], [$juaraB, $runnerA]],
            array_map(fn ($tie) => [$tie['home']['team']['id'], $tie['away']['team']['id']], $plan['ties']),
            'the automatic plan is the draw this test must differ from',
        );

        $this->actingAs($this->user, 'api')
            ->postJson($this->categoryUrl('/knockout'), [
                'seeding' => 'manual',
                'pairs' => [
                    [
                        'order' => 0,
                        'home_team_id' => $juaraA,
                        'away_team_id' => $juaraB,
                        // This tie is timed by hand; the other is left to the
                        // allocator, so the two can be compared afterwards.
                        'scheduled_at' => '2026-09-20T19:30:00+07:00',
                        'venue' => 'Stadion Utama',
                    ],
                    ['order' => 1, 'home_team_id' => $runnerA, 'away_team_id' => $runnerB],
                ],
            ])
            ->assertCreated();

        $semis = $this->knockoutRound(1);
        $this->assertCount(2, $semis);

        // The draw the organizer asked for, not the one the plan proposed.
        $this->assertSame($juaraA, $semis[0]['home_team_id']);
        $this->assertSame($juaraB, $semis[0]['away_team_id']);
        $this->assertSame($runnerA, $semis[1]['home_team_id']);
        $this->assertSame($runnerB, $semis[1]['away_team_id']);

        // The organizer's kickoff survived the slot allocator; the untouched
        // tie did not keep it, which is what proves the allocator ran at all.
        $this->assertSame('Stadion Utama', $semis[0]['venue']);
        $this->assertSame(
            '2026-09-20 12:30:00',
            Carbon::parse($semis[0]['scheduled_at'])->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertNotSame($semis[0]['scheduled_at'], $semis[1]['scheduled_at']);

        // The third-place tie shares the final's round and is told apart by
        // `bracket`, so the final must not be counted as two matches.
        $final = $this->knockoutRound(2);
        $third = $this->knockoutRound(2, 'third_place');

        $this->assertCount(1, $final);
        $this->assertCount(1, $third);
        $this->assertNull($third[0]['home_team_id'], 'nobody has lost a semifinal yet');
        $this->assertNull($third[0]['away_team_id']);

        // --- Semifinals: winners go up, losers go sideways --------------------

        $this->playAndConfirm($semis[0]['id'], 2, 0); // juaraA beats juaraB
        $this->playAndConfirm($semis[1]['id'], 0, 1); // runnerB beats runnerA

        $final = $this->knockoutRound(2)[0];
        $third = $this->knockoutRound(2, 'third_place')[0];

        $this->assertSame($juaraA, $final['home_team_id']);
        $this->assertSame($runnerB, $final['away_team_id']);

        // The beaten semifinalists, and only them. One lost at home and the
        // other away, so this also covers both sides of advanceLoser().
        $this->assertEqualsCanonicalizing(
            [$juaraB, $runnerA],
            [$third['home_team_id'], $third['away_team_id']],
        );

        // --- The third-place tie is a dead end --------------------------------

        $finalBefore = [$final['home_team_id'], $final['away_team_id']];

        $this->playAndConfirm($third['id'], 4, 2);

        $thirdPlayed = $this->knockoutRound(2, 'third_place')[0];
        $finalAfter = $this->knockoutRound(2)[0];

        $this->assertSame('finished', $thirdPlayed['status']);
        $this->assertTrue($thirdPlayed['confirmed']);

        // Its winner is third, not a finalist: the final is untouched, and no
        // round was invented above it to advance into.
        $this->assertSame($finalBefore, [$finalAfter['home_team_id'], $finalAfter['away_team_id']]);
        $this->assertSame([], $this->knockoutRound(3));

        // --- The final --------------------------------------------------------

        $this->playAndConfirm($finalAfter['id'], 1, 0);

        $champion = $this->knockoutRound(2)[0];
        $this->assertSame('finished', $champion['status']);
        $this->assertSame($juaraA, $champion['home_team_id']);
        $this->assertSame(1, $champion['home_score']);

        // 12 group + 2 semifinals + final + third place.
        $this->assertCount(16, $this->fixtures());
    }
}
