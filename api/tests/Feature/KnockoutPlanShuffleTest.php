<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Reshuffling the knockout *plan*: the qualifier slots redrawn at random and
 * saved, so the organizer can keep pressing "Undi Ulang" until a draw looks
 * right, then activate it. The plan-view twin of BracketShuffleTest, which does
 * the same for a generated single-elimination bracket.
 *
 * Two things are asserted hardest, because they are what makes this a draw and
 * not a coin toss: it actually rearranges the pairings, and it still keeps two
 * slots of the same group apart in the first round — the one promise automatic
 * seeding makes that a naive shuffle would break.
 *
 * Everything goes over HTTP and the pairing is random, so nothing hardcodes a
 * cast; the draw is read back through the slot keys ("A1", "B2") and their
 * group letters, the same reasoning as KnockoutPlanTest.
 */
class KnockoutPlanShuffleTest extends TestCase
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

        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);
        foreach (['max_active_events' => '5', 'max_teams_per_category' => '32'] as $key => $value) {
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
                'name' => 'Piala Undi 2026',
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

    /** @return array<string, mixed> */
    private function plan(): array
    {
        return $this->actingAs($this->user, 'api')
            ->getJson($this->categoryUrl('/knockout-plan'))
            ->assertOk()
            ->json('data');
    }

    private function savePlan(array $ties): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user, 'api')
            ->putJson($this->categoryUrl('/knockout-plan'), ['ties' => $ties]);
    }

    private function shuffle(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user, 'api')
            ->postJson($this->categoryUrl('/knockout-plan/shuffle'));
    }

    /**
     * The current draw as canonical cross-group pairs: each tie its two slot
     * keys sorted, and the ties themselves sorted, so home/away and tie order —
     * which the draw also shuffles — don't count as a different pairing.
     *
     * @return array<int, string>
     */
    private function pairing(): array
    {
        return collect($this->plan()['ties'])
            ->map(fn ($t) => collect([$t['home']['key'] ?? null, $t['away']['key'] ?? null])
                ->sort()->implode('-'))
            ->sort()->values()->all();
    }

    // ---------------------------------------------------------------------

    public function test_shuffle_stores_a_manual_plan_that_places_every_slot(): void
    {
        $this->createEvent();
        $this->registerTeams();
        $this->generateGroupStage();

        $this->assertSame('auto', $this->plan()['source']);

        $this->shuffle()
            ->assertOk()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'diacak'))
            ->assertJsonPath('data.source', 'manual');

        $plan = $this->plan();

        // A bracket of four: two ties, both full (no byes), every qualifier slot
        // placed exactly once — the same completeness saveKnockoutPlan enforces.
        $this->assertSame('manual', $plan['source']);
        $this->assertSame([], $plan['unplaced_slots']);
        $this->assertCount(2, $plan['ties']);

        $placed = collect($plan['ties'])
            ->flatMap(fn ($t) => [$t['home']['key'] ?? null, $t['away']['key'] ?? null])
            ->filter()->sort()->values()->all();

        $this->assertSame(['A1', 'A2', 'B1', 'B2'], $placed);
    }

    public function test_shuffle_keeps_group_mates_apart_in_the_first_round(): void
    {
        $this->createEvent();
        $this->registerTeams();
        $this->generateGroupStage();

        // Every draw of two groups' top two can cross the groups, so the same-
        // group rule is never merely lucky here — ten draws must all honour it.
        for ($i = 0; $i < 10; $i++) {
            $this->shuffle()->assertOk();

            foreach ($this->plan()['ties'] as $tie) {
                $home = $tie['home'];
                $away = $tie['away'];

                if ($home === null || $away === null) {
                    continue;
                }

                if ($home['group'] !== null && $away['group'] !== null) {
                    $this->assertNotSame(
                        $home['group'],
                        $away['group'],
                        'two slots from the same group were paired in the first round',
                    );
                }
            }
        }
    }

    public function test_shuffle_actually_redraws(): void
    {
        $this->createEvent();
        $this->registerTeams();
        $this->generateGroupStage();

        $this->shuffle()->assertOk();
        $original = $this->pairing();

        // A draw may legitimately land where it started, so one repeat proves
        // nothing; ten that never move would mean it is not shuffling. With the
        // group rule there are two crossing pairings, so each redraw is a coin
        // flip and ten will differ with near certainty.
        $moved = false;
        for ($i = 0; $i < 10 && ! $moved; $i++) {
            $this->shuffle()->assertOk();
            $moved = $this->pairing() !== $original;
        }

        $this->assertTrue($moved, 'the pairing never changed in ten shuffles');
    }

    public function test_shuffle_works_before_the_group_stage_is_finished(): void
    {
        $this->createEvent();
        $this->registerTeams();
        $this->generateGroupStage();

        // Not a single group game is played. Generation is blocked here; the
        // draw is not — writing down "juara A v runner-up B" early is exactly
        // the wait this feature removes.
        $this->assertGreaterThan(0, $this->plan()['group_matches_pending']);

        $this->shuffle()->assertOk()->assertJsonPath('data.source', 'manual');
    }

    public function test_shuffle_overwrites_a_saved_manual_plan(): void
    {
        $this->createEvent();
        $this->registerTeams();
        $this->generateGroupStage();

        // A fixed draw the organizer typed by hand: winners kept apart.
        $this->savePlan([
            ['order' => 0, 'home' => 'A1', 'away' => 'B2'],
            ['order' => 1, 'home' => 'A2', 'away' => 'B1'],
        ])->assertOk();

        $saved = $this->pairing();

        // Shuffle until it lands on the other crossing pairing, proving the
        // stored draw is genuinely replaced rather than left in place.
        $moved = false;
        for ($i = 0; $i < 10 && ! $moved; $i++) {
            $this->shuffle()->assertOk()->assertJsonPath('data.source', 'manual');
            $moved = $this->pairing() !== $saved;
        }

        $this->assertTrue($moved, 'the saved plan was never overwritten in ten shuffles');
    }

    public function test_shuffle_refuses_a_non_hybrid_category(): void
    {
        $this->createEvent('league');
        $this->registerTeams();

        $this->shuffle()->assertStatus(422);
    }
}
