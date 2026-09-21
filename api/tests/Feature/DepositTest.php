<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Security deposit monitoring: the balance derived from a flat per-event
 * jaminan minus card deductions.
 *
 * Every test compares two things that must come out different — two teams in
 * the same event, cards on a confirmed match against cards on one that isn't,
 * a deposit namespace saved alongside discipline against one saved alone.
 * Asserting only "a balance shows up" would pass just as happily against an
 * engine that never deducts anything.
 */
class DepositTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private User $user;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);
        $this->organization = Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $this->user->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes  extra event columns (rules_config…)
     */
    private function event(array $attributes = []): Event
    {
        $event = $this->organization->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Deposit Cup',
            'slug' => 'deposit-cup-'.uniqid(),
            'sport_type' => 'mini_soccer',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
            ...$attributes,
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
    private function deposits(Event $event): array
    {
        return $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/organizations/{$this->organization->id}/events/{$event->id}/deposits")
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function teamRow(array $payload, string $teamId): array
    {
        $row = collect($payload['teams'])->firstWhere('team_id', $teamId);

        $this->assertNotNull($row, 'team missing from the deposit payload');

        return $row;
    }

    // ---- tests ----

    public function test_cards_deduct_from_the_flat_deposit_and_a_clean_team_keeps_it_all(): void
    {
        $event = $this->event(['rules_config' => [
            'deposit' => ['amount' => 500000, 'yellow_deduction' => 50000, 'red_deduction' => 150000],
        ]]);
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        $matchId = $this->fixture($event, $home, $away);
        $this->finish($matchId);
        $this->cards($matchId, [$players['Budi'] => ['yellow' => 2, 'red' => 1]]);

        $payload = $this->deposits($event);

        $this->assertTrue($payload['enabled']);

        $garuda = $this->teamRow($payload, $home);
        $this->assertSame(2, $garuda['yellow_count']);
        $this->assertSame(1, $garuda['red_count']);
        $this->assertSame(2 * 50000 + 150000, $garuda['deduction']);
        $this->assertSame(500000 - (2 * 50000 + 150000), $garuda['balance']);

        $rajawali = $this->teamRow($payload, $away);
        $this->assertSame(0, $rajawali['yellow_count']);
        $this->assertSame(0, $rajawali['red_count']);
        $this->assertSame(500000, $rajawali['balance'], 'a team with no cards must keep its full deposit');
    }

    public function test_zero_amount_disables_the_feature(): void
    {
        $event = $this->event();
        $this->team($event, 'Garuda FC', ['Budi']);

        $payload = $this->deposits($event);

        $this->assertFalse($payload['enabled']);
        $this->assertSame([], $payload['teams']);
    }

    public function test_only_a_confirmed_result_deducts(): void
    {
        $event = $this->event(['rules_config' => [
            'deposit' => ['amount' => 500000, 'yellow_deduction' => 50000, 'red_deduction' => 150000],
        ]]);
        [$home, $players] = $this->team($event, 'Garuda FC', ['Budi']);
        [$away] = $this->team($event, 'Rajawali United', ['Lawan']);

        $matchId = $this->fixture($event, $home, $away);
        // Not finished/confirmed yet — stats can still be typed in ahead of the
        // whistle, but they must not count until the result is signed off.
        $this->cards($matchId, [$players['Budi'] => ['yellow' => 1]]);

        $payload = $this->deposits($event);
        $this->assertSame(500000, $this->teamRow($payload, $home)['balance'], 'cards on an unconfirmed match were deducted');

        $this->finish($matchId);
        $after = $this->deposits($event);
        $this->assertSame(500000 - 50000, $this->teamRow($after, $home)['balance']);
    }

    public function test_saving_a_deposit_namespace_does_not_wipe_an_existing_discipline_namespace(): void
    {
        $event = $this->event(['rules_config' => [
            'discipline' => ['yellow_threshold' => 3],
        ]]);

        $this->actingAs($this->user, 'api')
            ->putJson("/api/v1/organizations/{$this->organization->id}/events/{$event->id}", [
                'rules_config' => [
                    'deposit' => ['amount' => 500000, 'yellow_deduction' => 50000, 'red_deduction' => 150000],
                ],
            ])
            ->assertOk();

        $event->refresh();

        $this->assertSame(3, $event->rules_config['discipline']['yellow_threshold'] ?? null);
        $this->assertSame(500000, $event->rules_config['deposit']['amount'] ?? null);
    }
}
