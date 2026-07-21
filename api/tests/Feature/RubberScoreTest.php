<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A squad tie is not one scoreline. "Spanyol 3-0 Argentina" is the count of
 * partai won, each with its own lineup and its own set scores, so the tie's
 * result is rolled up from them and never typed in.
 */
class RubberScoreTest extends TestCase
{
    use RefreshDatabase;

    private const FORMAT = [
        ['label' => 'Ganda Putra', 'type' => 'double'],
        ['label' => 'Tunggal Putra', 'type' => 'single'],
        ['label' => 'Ganda Campuran', 'type' => 'double'],
    ];

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    /** A badminton beregu category with two four-player squads. */
    private function beregu(Organization $org, string $format = 'league'): Event
    {
        $event = $org->events()->create([
            'name' => 'Piala Beregu',
            'slug' => 'beregu-'.uniqid(),
            'sport_type' => 'badminton',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $category = $event->categories()->create([
            'name' => 'Beregu Campuran',
            'slug' => 'beregu-campuran',
            'participant_type' => 'team',
            'rubber_format' => self::FORMAT,
            'tournament_format' => $format,
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        $rosters = [
            'Spanyol' => ['Dimas', 'Ammar', 'Jo', 'Yolan'],
            'Argentina' => ['Ucang', 'Devan', 'Ratih', 'Bagas'],
        ];

        foreach ($rosters as $name => $players) {
            $team = $event->teams()->create([
                'category_id' => $category->id,
                'name' => $name,
                'status' => 'approved',
            ]);

            foreach ($players as $player) {
                $team->players()->create(['full_name' => $player]);
            }
        }

        return $event->load('categories');
    }

    private function scheduleUrl(Organization $org, Event $event): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule";
    }

    private function team(Event $event, string $name): Team
    {
        return $event->teams()->where('name', $name)->with('players')->firstOrFail();
    }

    /** Play out the exact tie from the spec: Spanyol beat Argentina 3-0. */
    private function playTie(User $user, Organization $org, GameMatch $match): void
    {
        $home = $this->team($match->event, 'Spanyol');
        $away = $this->team($match->event, 'Argentina');

        $player = fn (Team $team, string $name) => $team->players->firstWhere('full_name', $name)->id;

        $scores = [
            // Ganda Putra: Dimas/Ammar vs Ucang/Devan = 21-16 / 22-20
            [['Dimas', 'Ammar'], ['Ucang', 'Devan'], [[21, 16], [22, 20]]],
            // Tunggal Putra: Dimas vs Ucang = 15-21 / 21-18 / 24-22
            [['Dimas'], ['Ucang'], [[15, 21], [21, 18], [24, 22]]],
            // Ganda Campuran: Jo/Yolan vs Ucang/Ratih = 22-15 / 23-21
            [['Jo', 'Yolan'], ['Ucang', 'Ratih'], [[22, 15], [23, 21]]],
        ];

        foreach ($match->rubbers as $i => $rubber) {
            [$homeNames, $awayNames, $sets] = $scores[$i];

            $this->actingAs($user, 'api')
                ->patchJson("/api/v1/organizations/{$org->id}/rubbers/{$rubber->id}", [
                    'home_player_ids' => array_map(fn ($n) => $player($home, $n), $homeNames),
                    'away_player_ids' => array_map(fn ($n) => $player($away, $n), $awayNames),
                    'sets' => array_map(fn ($s) => ['home' => $s[0], 'away' => $s[1]], $sets),
                ])
                ->assertOk();
        }
    }

    public function test_generated_fixtures_are_born_with_the_category_template(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->beregu($org);

        $this->actingAs($user, 'api')
            ->postJson($this->scheduleUrl($org, $event))
            ->assertCreated();

        $match = $event->matches()->with('rubbers')->firstOrFail();

        $this->assertSame(
            ['Ganda Putra', 'Tunggal Putra', 'Ganda Campuran'],
            $match->rubbers->pluck('label')->all(),
        );
        $this->assertSame(['double', 'single', 'double'], $match->rubbers->pluck('type')->all());
        // Nothing played yet is no result — not a 0-0 draw.
        $this->assertNull($match->home_score);
    }

    public function test_tie_score_is_rolled_up_from_its_partai(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->beregu($org);

        $this->actingAs($user, 'api')->postJson($this->scheduleUrl($org, $event))->assertCreated();

        $match = $event->matches()->with('rubbers')->firstOrFail();
        // The generator may seat either squad at home; normalise so the expected
        // 3-0 is always Spanyol's.
        $match->update([
            'home_team_id' => $this->team($event, 'Spanyol')->id,
            'away_team_id' => $this->team($event, 'Argentina')->id,
        ]);

        $this->playTie($user, $org, $match->fresh()->load('rubbers'));

        $match = $match->fresh();
        $this->assertSame(3, $match->home_score);
        $this->assertSame(0, $match->away_score);
        $this->assertSame('finished', $match->status);
        // A tie has no single run of sets — each partai keeps its own.
        $this->assertNull($match->sets);

        // Middle partai went to three games and Spanyol still took it 2-1.
        $decider = $match->rubbers->firstWhere('label', 'Tunggal Putra');
        $this->assertSame(2, $decider->home_score);
        $this->assertSame(1, $decider->away_score);
    }

    public function test_standings_count_partai_won_and_aggregate_set_points(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->beregu($org);
        $category = $event->categories->first();

        $this->actingAs($user, 'api')->postJson($this->scheduleUrl($org, $event))->assertCreated();

        $match = $event->matches()->firstOrFail();
        $match->update([
            'home_team_id' => $this->team($event, 'Spanyol')->id,
            'away_team_id' => $this->team($event, 'Argentina')->id,
        ]);

        $this->playTie($user, $org, $match->fresh()->load('rubbers'));

        $rows = $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/standings")
            ->assertOk()
            ->json('data');

        $spanyol = collect($rows)->firstWhere('team.name', 'Spanyol');
        $argentina = collect($rows)->firstWhere('team.name', 'Argentina');

        // "Goals" are partai here — 3-0 — and the win is worth the usual 3 points.
        $this->assertSame(3, $spanyol['goals_for']);
        $this->assertSame(0, $spanyol['goals_against']);
        $this->assertSame(3, $spanyol['points']);
        $this->assertSame(0, $argentina['points']);

        // 21+22+15+21+24 + 22+23 = 148 for Spanyol, 16+20+21+18+22 + 15+21 = 133.
        $this->assertSame(148, $spanyol['points_for']);
        $this->assertSame(133, $spanyol['points_against']);
        $this->assertSame(133, $argentina['points_for']);
    }

    public function test_a_level_tie_counts_in_a_league_but_waits_in_a_knockout(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        // A two-partai tie so it can end 1-1.
        foreach (['league', 'knockout_single'] as $format) {
            $event = $this->beregu($org, format: $format);
            $category = $event->categories->first();
            $category->update([
                'rubber_format' => [
                    ['label' => 'Tunggal Putra', 'type' => 'single'],
                    ['label' => 'Tunggal Putri', 'type' => 'single'],
                ],
            ]);

            $this->actingAs($user, 'api')->postJson($this->scheduleUrl($org, $event))->assertCreated();

            $match = $event->matches()->with('rubbers')->firstOrFail();

            foreach ($match->rubbers as $i => $rubber) {
                // One partai each: 1-1.
                $sets = $i === 0 ? [[21, 15]] : [[15, 21]];

                $this->actingAs($user, 'api')
                    ->patchJson("/api/v1/organizations/{$org->id}/rubbers/{$rubber->id}", [
                        'sets' => array_map(fn ($s) => ['home' => $s[0], 'away' => $s[1]], $sets),
                    ])
                    ->assertOk();
            }

            $match = $match->fresh();
            $this->assertSame(1, $match->home_score);
            $this->assertSame(1, $match->away_score);

            if ($format === 'league') {
                // A drawn league tie is a real result worth a point each.
                $this->assertNotNull($match->confirmed_at);
            } else {
                // Nobody to send onward, so it waits for the organizer.
                $this->assertNull($match->confirmed_at);
            }
        }
    }

    public function test_a_tie_scoreline_cannot_be_typed_in_directly(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->beregu($org);

        $this->actingAs($user, 'api')->postJson($this->scheduleUrl($org, $event))->assertCreated();
        $match = $event->matches()->firstOrFail();

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}", [
                'status' => 'finished',
                'sets' => [['home' => 21, 'away' => 10]],
            ])
            ->assertStatus(422);

        $this->assertNull($match->fresh()->home_score);
    }

    public function test_a_lineup_must_come_from_its_own_side_at_the_right_size(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->beregu($org);

        $this->actingAs($user, 'api')->postJson($this->scheduleUrl($org, $event))->assertCreated();

        $match = $event->matches()->firstOrFail();
        $match->update([
            'home_team_id' => $this->team($event, 'Spanyol')->id,
            'away_team_id' => $this->team($event, 'Argentina')->id,
        ]);

        $home = $this->team($event, 'Spanyol');
        $away = $this->team($event, 'Argentina');
        $doubles = $match->fresh()->rubbers->firstWhere('label', 'Ganda Putra');
        $url = "/api/v1/organizations/{$org->id}/rubbers/{$doubles->id}";

        // A pair is two players, not one.
        $this->actingAs($user, 'api')
            ->patchJson($url, ['home_player_ids' => [$home->players[0]->id]])
            ->assertStatus(422);

        // …and not the same player twice.
        $this->actingAs($user, 'api')
            ->patchJson($url, ['home_player_ids' => [$home->players[0]->id, $home->players[0]->id]])
            ->assertStatus(422);

        // Borrowing an opponent is not a lineup.
        $this->actingAs($user, 'api')
            ->patchJson($url, ['home_player_ids' => [$home->players[0]->id, $away->players[0]->id]])
            ->assertStatus(422);

        $this->assertNull($doubles->fresh()->home_player_ids);
    }

    public function test_partai_of_a_knockout_tie_carry_the_winner_onward(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->beregu($org, format: 'knockout_single');
        $category = $event->categories->first();

        $this->actingAs($user, 'api')->postJson($this->scheduleUrl($org, $event))->assertCreated();

        // Two teams = a one-match bracket, so add a round the winner advances
        // into rather than asserting on nothing.
        $final = $event->matches()->orderBy('round')->firstOrFail();
        $next = GameMatch::create([
            'event_id' => $event->id,
            'category_id' => $category->id,
            'round' => $final->round + 1,
            'order' => 0,
        ]);

        $final->update([
            'home_team_id' => $this->team($event, 'Spanyol')->id,
            'away_team_id' => $this->team($event, 'Argentina')->id,
        ]);

        $this->playTie($user, $org, $final->fresh()->load('rubbers'));

        // The owner administers the org, so saving the last partai signs the tie
        // off — and a signed-off knockout result seats its winner.
        $final = $final->fresh();
        $this->assertNotNull($final->confirmed_at);
        $this->assertSame($final->home_team_id, $next->fresh()->home_team_id);

        // A confirmed tie is not editable until it is unconfirmed — otherwise the
        // squad already seated in the next round would be stranded there.
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/rubbers/{$final->rubbers->first()->id}", [
                'sets' => [['home' => 10, 'away' => 21]],
            ])
            ->assertStatus(422);
    }
}
