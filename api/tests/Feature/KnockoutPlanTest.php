<?php

namespace Tests\Feature;

use App\Models\EventCategory;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The knockout bracket drawn in *slots* before the groups finish: "Juara Grup A"
 * meets "Runner-up Grup B", saved on day one, activated on the last day.
 *
 * Everything goes over HTTP, and the group draw is random, so nothing here
 * hardcodes a cast. The plan is asserted through the *relationships* it claims
 * (which group's winner met which group's runner-up), read back from the
 * standings after the fact — the same reasoning as HybridManualBracketE2ETest.
 *
 * The load-bearing assertion is that the activated bracket differs from what
 * automatic seeding would have produced. Automatic seeding always crosses the
 * groups, so a bracket pairing the two group winners can only have come from
 * the saved plan.
 */
class KnockoutPlanTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private User $user;

    private Organization $org;

    private string $eventId;

    private string $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // No plan is attached here: entitlements hang off the event now, and
        // createEvent() buys the credit that carries them. The plan this used to
        // build — on `organizations.plan_id`, keyed `max_active_events` — was
        // reaching a column that no longer exists and a feature key that was
        // retired, so it had stopped granting anything at all.
        $this->org = $this->orgFor($this->user);
    }

    private function orgUrl(string $path = ''): string
    {
        return "/api/v1/organizations/{$this->org->id}".$path;
    }

    private function categoryUrl(string $path): string
    {
        return $this->orgUrl("/events/{$this->eventId}/categories/{$this->categoryId}".$path);
    }

    /** Two groups of four, top two through — a bracket of exactly four slots. */
    private function createEvent(string $format = 'hybrid'): void
    {
        $category = ['name' => 'Umum', 'tournament_format' => $format, 'registration_fee' => 0];

        if ($format === 'hybrid') {
            $category['bracket_config'] = [
                'groups' => 2,
                'teams_per_group' => 4,
                'points' => ['win' => 3, 'draw' => 1, 'lose' => 0],
                'qualification' => ['top_per_group' => 2],
                'draw_method' => 'random',
            ];
        }

        // Creating an event spends a paid credit.
        $this->creditFor($this->org);

        $response = $this->actingAs($this->user, 'api')
            ->postJson($this->orgUrl('/events'), [
                'name' => 'Piala Rencana 2026',
                'sport_type' => 'football',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-30',
                'timezone' => 'Asia/Jakarta',
                'categories' => [$category],
            ])
            ->assertCreated();

        $this->eventId = $response->json('data.id');
        $this->categoryId = $response->json('data.categories.0.id');
    }

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

    private function generateGroupStage(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson($this->categoryUrl('/schedule'), [
                'start_date' => '2026-09-01',
                'daily_start' => '08:00',
                'daily_end' => '21:00',
                'venues' => 2,
            ])
            ->assertCreated();
    }

    /** @return array<int, array<string, mixed>> */
    private function fixtures(): array
    {
        return $this->actingAs($this->user, 'api')
            ->getJson($this->categoryUrl('/matches'))
            ->assertOk()
            ->json('data');
    }

    /** @return array<string, mixed> */
    private function plan(): array
    {
        return $this->actingAs($this->user, 'api')
            ->getJson($this->categoryUrl('/knockout-plan'))
            ->assertOk()
            ->json('data');
    }

    /**
     * Play out every group fixture and sign it off.
     *
     * A team named in `$losers` loses to any team that is not; every other
     * fixture goes to the alphabetically later name. Both halves are read off
     * the fixture itself, so the draw stays random — nothing here knows which
     * club landed in which group — while the final table becomes something the
     * caller can state in advance. In a group of four that means the teams
     * outside `$losers` take the qualifying places: each beats both doomed
     * teams, and their own meeting only decides which of them finishes top.
     *
     * The rule used to be "home wins every game", which left the played table
     * to how the random draw dealt out home games. That alone was survivable;
     * what made the test flaky is that the table it was compared *against* —
     * the nil-results one — is itself a fresh permutation on every run (see
     * where `$before` is read). Two moving quantities, and an assertion that
     * they differ. Pinning this side is what lets the caller pin both.
     *
     * @param  array<int, string>  $losers  team ids that must finish bottom
     */
    private function playGroupStage(array $losers = []): void
    {
        foreach ($this->fixtures() as $m) {
            if ($m['stage'] !== 'group') {
                continue;
            }

            $homeDoomed = in_array($m['home_team']['id'], $losers, true);
            $awayDoomed = in_array($m['away_team']['id'], $losers, true);

            $homeWins = $homeDoomed === $awayDoomed
                ? strcmp($m['home_team']['name'], $m['away_team']['name']) > 0
                : $awayDoomed;

            $this->actingAs($this->user, 'api')
                ->patchJson($this->orgUrl("/matches/{$m['id']}"), [
                    'status' => 'finished',
                    'home_score' => $homeWins ? 3 : 1,
                    'away_score' => $homeWins ? 1 : 3,
                ])
                ->assertOk();

            $this->actingAs($this->user, 'api')
                ->patchJson($this->orgUrl("/matches/{$m['id']}/confirm"), ['confirmed' => true])
                ->assertOk();
        }
    }

    /**
     * Whoever holds each slot right now, keyed by slot key ("A1", "B2").
     *
     * @return array<string, string> slot key => team id
     */
    private function occupants(): array
    {
        return collect($this->plan()['slots'])
            ->filter(fn ($slot) => $slot['team'] !== null)
            ->mapWithKeys(fn ($slot) => [$slot['key'] => $slot['team']['id']])
            ->all();
    }

    /** The draw automatic seeding would never make: the two group winners meet. */
    private function winnersMeetPlan(): array
    {
        return [
            ['order' => 0, 'home' => 'A1', 'away' => 'B1'],
            ['order' => 1, 'home' => 'A2', 'away' => 'B2'],
        ];
    }

    private function savePlan(array $ties): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user, 'api')
            ->putJson($this->categoryUrl('/knockout-plan'), ['ties' => $ties]);
    }

    // ---------------------------------------------------------------------

    public function test_an_undrawn_bracket_proposes_nothing(): void
    {
        $this->createEvent();
        $this->registerTeams();
        $this->generateGroupStage();

        $plan = $this->plan();

        // The shape of the bracket is known — four places, two ties — but not a
        // single pairing. Automatic seeding could fill these in and used to;
        // showing its guess reads as a decision the organizer never made.
        $this->assertSame('auto', $plan['source']);
        $this->assertCount(2, $plan['ties']);
        $this->assertCount(4, $plan['slots']);

        foreach ($plan['ties'] as $tie) {
            $this->assertNull($tie['home']);
            $this->assertNull($tie['away']);
        }

        // Every place is still named and occupied, so the editor has a pool and
        // the page has something to show.
        $this->assertSame(['A1', 'B1', 'A2', 'B2'], array_column($plan['slots'], 'key'));
        $this->assertNotNull($plan['slots'][0]['team']);
    }

    public function test_plan_is_saved_in_slots_and_read_back_with_live_occupants(): void
    {
        $this->createEvent();
        $this->registerTeams();
        $this->generateGroupStage();

        // Not a single group game has been played, which is the whole point:
        // the draw is made before anyone knows who will fill the slots.
        $this->assertSame('auto', $this->plan()['source']);

        $this->savePlan($this->winnersMeetPlan())->assertOk();

        $plan = $this->plan();

        $this->assertSame('manual', $plan['source']);
        $this->assertFalse($plan['stale']);
        $this->assertSame([], $plan['unplaced_slots']);
        $this->assertSame(
            [['A1', 'B1'], ['A2', 'B2']],
            array_map(fn ($tie) => [$tie['home']['key'], $tie['away']['key']], $plan['ties']),
        );

        // Nothing about the teams is stored, so the occupants can only be the
        // live table. At nil results every rule that answers to a result has
        // nothing to answer with, so the order comes down to the drawing lot —
        // `crc32(category id . team id)`, which is a fresh permutation on every
        // run. Whoever these four are is therefore not predictable here, and
        // must never be assumed.
        $before = $this->occupants();
        $this->assertSame($before['A1'], $plan['ties'][0]['home']['team']['id']);

        // Which is why the group stage is played *against* them: the four the
        // empty table happened to show are made to lose, so the four that
        // qualify are guaranteed to be four different teams no matter how the
        // lot fell. Nothing here needs to know the draw.
        $this->playGroupStage(array_values($before));

        $played = $this->plan();
        $after = $this->occupants();

        $this->assertSame($after['A1'], $played['ties'][0]['home']['team']['id']);
        $this->assertSame($after['B1'], $played['ties'][0]['away']['team']['id']);
        $this->assertSame('manual', $played['source'], 'the plan survives the group stage');

        // The pairing is fixed; who fills it is not. Disjoint, not merely
        // different: occupants frozen at save time would still be naming the
        // lot's four, and so would occupants tracking anything other than the
        // live table.
        $this->assertEmpty(
            array_intersect(array_values($before), array_values($after)),
            'the slots must track the standings, not freeze at save time',
        );
    }

    public function test_activation_builds_the_bracket_from_the_saved_plan(): void
    {
        $this->createEvent();
        $this->registerTeams();
        $this->generateGroupStage();
        $this->savePlan($this->winnersMeetPlan())->assertOk();
        $this->playGroupStage();

        $occupants = $this->occupants();

        // Activation carries no seeding of its own — the whole point is that
        // the decision was made weeks earlier.
        $this->actingAs($this->user, 'api')
            ->postJson($this->categoryUrl('/knockout'), [])
            ->assertCreated()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'rencana bracket'));

        $semis = array_values(array_filter(
            $this->fixtures(),
            fn ($m) => $m['stage'] === 'knockout' && $m['round'] === 1,
        ));

        $this->assertCount(2, $semis);

        // Automatic seeding always crosses the groups (a winner draws the other
        // group's runner-up), so two group winners in one tie proves the saved
        // plan was honoured rather than recomputed.
        $this->assertSame($occupants['A1'], $semis[0]['home_team_id']);
        $this->assertSame($occupants['B1'], $semis[0]['away_team_id']);
        $this->assertSame($occupants['A2'], $semis[1]['home_team_id']);
        $this->assertSame($occupants['B2'], $semis[1]['away_team_id']);
    }

    public function test_a_planned_kickoff_survives_the_slot_allocator(): void
    {
        $this->createEvent();
        $this->registerTeams();
        $this->generateGroupStage();

        $this->savePlan([
            [
                'order' => 0,
                'home' => 'A1',
                'away' => 'B1',
                'scheduled_at' => '2026-09-20T19:30:00+07:00',
                'venue' => 'Stadion Utama',
            ],
            // Left to the allocator, so the two can be compared afterwards.
            ['order' => 1, 'home' => 'A2', 'away' => 'B2'],
        ])->assertOk();

        $this->playGroupStage();

        $this->actingAs($this->user, 'api')
            ->postJson($this->categoryUrl('/knockout'), [])
            ->assertCreated();

        $semis = array_values(array_filter(
            $this->fixtures(),
            fn ($m) => $m['stage'] === 'knockout' && $m['round'] === 1,
        ));

        $this->assertSame('Stadion Utama', $semis[0]['venue']);
        $this->assertSame(
            '2026-09-20 12:30:00',
            Carbon::parse($semis[0]['scheduled_at'])->utc()->format('Y-m-d H:i:s'),
        );
        // The other tie moved, which is what proves the allocator ran at all.
        $this->assertNotSame($semis[0]['scheduled_at'], $semis[1]['scheduled_at']);
    }

    public function test_changing_the_qualification_rules_marks_the_plan_stale_and_blocks_activation(): void
    {
        $this->createEvent();
        $this->registerTeams();
        $this->generateGroupStage();
        $this->savePlan($this->winnersMeetPlan())->assertOk();
        $this->playGroupStage();

        // Only group winners qualify now: A2/B2 no longer exist, and the plan
        // still names them.
        $category = EventCategory::findOrFail($this->categoryId);
        $category->update([
            'bracket_config' => [
                ...$category->bracket_config,
                'qualification' => ['top_per_group' => 1],
            ],
        ]);

        $plan = $this->plan();

        $this->assertTrue($plan['stale']);
        $this->assertSame('manual', $plan['source'], 'a stale plan is reported, never discarded');

        $this->actingAs($this->user, 'api')
            ->postJson($this->categoryUrl('/knockout'), [])
            ->assertStatus(422)
            ->assertJsonPath('errors.feature', 'knockout_plan_incomplete');
    }

    public function test_a_plan_that_leaves_a_qualifier_slot_out_blocks_activation(): void
    {
        $this->createEvent();
        $this->registerTeams();
        $this->generateGroupStage();
        $this->savePlan($this->winnersMeetPlan())->assertOk();
        $this->playGroupStage();

        // Widen the field instead of narrowing it: every slot the plan names
        // still exists, but two "best third place" ones appear that it never
        // placed. Nothing is stale — it is simply incomplete, and the two are
        // reported separately because they are fixed differently.
        $category = EventCategory::findOrFail($this->categoryId);
        $category->update([
            'bracket_config' => [
                ...$category->bracket_config,
                'qualification' => ['top_per_group' => 2, 'best_thirds' => 2],
            ],
        ]);

        $plan = $this->plan();

        $this->assertFalse($plan['stale'], 'A1/A2/B1/B2 still exist');
        $this->assertEqualsCanonicalizing(
            ['BT1', 'BT2'],
            array_column($plan['unplaced_slots'], 'key'),
        );

        $this->actingAs($this->user, 'api')
            ->postJson($this->categoryUrl('/knockout'), [])
            ->assertStatus(422)
            ->assertJsonPath('errors.feature', 'knockout_plan_incomplete');
    }

    public function test_explicit_team_seeding_still_wins_over_a_saved_plan(): void
    {
        $this->createEvent();
        $this->registerTeams();
        $this->generateGroupStage();
        $this->savePlan($this->winnersMeetPlan())->assertOk();
        $this->playGroupStage();

        $occupants = $this->occupants();

        // The mirror image of the saved plan: winners split apart again.
        $this->actingAs($this->user, 'api')
            ->postJson($this->categoryUrl('/knockout'), [
                'seeding' => 'manual',
                'pairs' => [
                    ['order' => 0, 'home_team_id' => $occupants['A1'], 'away_team_id' => $occupants['B2']],
                    ['order' => 1, 'home_team_id' => $occupants['B1'], 'away_team_id' => $occupants['A2']],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'seeding manual'));

        $semis = array_values(array_filter(
            $this->fixtures(),
            fn ($m) => $m['stage'] === 'knockout' && $m['round'] === 1,
        ));

        $this->assertSame($occupants['B2'], $semis[0]['away_team_id']);
        $this->assertSame($occupants['A2'], $semis[1]['away_team_id']);
    }

    public function test_the_plan_refuses_duplicate_slots_duplicate_orders_and_gaps(): void
    {
        $this->createEvent();
        $this->registerTeams();
        $this->generateGroupStage();

        // One slot in two ties.
        $this->savePlan([
            ['order' => 0, 'home' => 'A1', 'away' => 'B1'],
            ['order' => 1, 'home' => 'A1', 'away' => 'B2'],
        ])->assertStatus(422);

        // One tie sent twice.
        $this->savePlan([
            ['order' => 0, 'home' => 'A1', 'away' => 'B1'],
            ['order' => 0, 'home' => 'A2', 'away' => 'B2'],
        ])->assertStatus(422);

        // A2/B2 qualify and would have nowhere to play.
        $this->savePlan([
            ['order' => 0, 'home' => 'A1', 'away' => 'B1'],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['ties']]);

        // A slot that isn't a qualifier at all.
        $this->savePlan([
            ['order' => 0, 'home' => 'A1', 'away' => 'Z9'],
            ['order' => 1, 'home' => 'A2', 'away' => 'B2'],
        ])->assertStatus(422);

        $this->assertSame('auto', $this->plan()['source'], 'nothing was stored');
    }

    public function test_resetting_the_plan_returns_to_automatic_seeding(): void
    {
        $this->createEvent();
        $this->registerTeams();
        $this->generateGroupStage();
        $this->savePlan($this->winnersMeetPlan())->assertOk();

        $this->actingAs($this->user, 'api')
            ->deleteJson($this->categoryUrl('/knockout-plan'))
            ->assertOk()
            ->assertJsonPath('data.source', 'auto');

        $this->playGroupStage();
        $occupants = $this->occupants();

        $this->actingAs($this->user, 'api')
            ->postJson($this->categoryUrl('/knockout'), [])
            ->assertCreated();

        $semis = array_values(array_filter(
            $this->fixtures(),
            fn ($m) => $m['stage'] === 'knockout' && $m['round'] === 1,
        ));

        // Back to crossing the groups.
        $this->assertSame($occupants['A1'], $semis[0]['home_team_id']);
        $this->assertSame($occupants['B2'], $semis[0]['away_team_id']);
    }

    public function test_the_plan_is_never_exposed_publicly(): void
    {
        $this->createEvent();
        $this->registerTeams();
        $this->generateGroupStage();
        $this->savePlan($this->winnersMeetPlan())->assertOk();

        $this->actingAs($this->user, 'api')
            ->patchJson($this->orgUrl("/events/{$this->eventId}/status"), ['status' => 'open'])
            ->assertOk();

        $event = $this->actingAs($this->user, 'api')
            ->getJson($this->orgUrl("/events/{$this->eventId}"))
            ->assertOk()
            ->json('data');

        // A draw the organizer is still free to rearrange is a working
        // document, not an announcement. Published, it would commit them to
        // pairings in front of every entrant.
        $this->getJson(
            "/api/v1/public/events/{$this->org->slug}/{$event['slug']}"
            ."/categories/{$event['categories'][0]['slug']}/knockout-plan"
        )->assertNotFound();

        // The organizer still has it, on their own tenant-scoped route.
        $this->assertSame('manual', $this->plan()['source']);
    }

    public function test_a_league_category_has_no_knockout_plan(): void
    {
        $this->createEvent('league');

        $this->savePlan([['order' => 0, 'home' => 'A1', 'away' => 'B1']])
            ->assertStatus(422);

        $this->actingAs($this->user, 'api')
            ->getJson($this->categoryUrl('/knockout-plan'))
            ->assertStatus(422);
    }
}
