<?php

namespace Tests\Feature;

use App\Models\ConfigOption;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\Catalog;
use App\Services\StandingService;
use App\Support\HybridConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A table is not shaped the same in every sport. Football counts gol; badminton
 * singles counts game menang with the raw points behind them; a squad tie counts
 * partai, then games, then points. The tiebreakers ride on those same columns,
 * so both follow one derived answer — EventCategory::standingsContext().
 *
 * Everything here is asserted by *comparing* a set-based event with a goal-based
 * one on the same shape of data: "badminton totals its points" proves nothing on
 * its own if football quietly does too.
 */
class StandingsFormatTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    /**
     * An event with one category and two approved entrants.
     *
     * @param  array<string, mixed>  $category  overrides for the category row
     */
    private function event(Organization $org, string $sport, array $category = []): Event
    {
        $event = $org->events()->create([
            'name' => 'Kejurnas',
            'slug' => 'kejurnas-'.uniqid(),
            'sport_type' => $sport,
            'status' => 'ongoing',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $model = $event->categories()->create([
            'name' => 'Utama',
            'slug' => 'utama',
            'participant_type' => 'single',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
            ...$category,
        ]);

        foreach (['Ammar', 'Dimas'] as $name) {
            $event->teams()->create([
                'category_id' => $model->id,
                'name' => $name,
                'status' => 'approved',
            ]);
        }

        return $event->load('categories');
    }

    /**
     * A confirmed result. `sets` is what a set-based sport stores alongside the
     * scoreline; a goal sport leaves it null.
     *
     * @param  array<int, array{home: int, away: int}>|null  $sets
     */
    private function play(Event $event, int $home, int $away, ?array $sets = null): void
    {
        $category = $event->categories->first();

        $event->matches()->create([
            'category_id' => $category->id,
            'round' => 1,
            'leg' => 1,
            'order' => 1,
            'home_team_id' => $event->teams()->where('name', 'Ammar')->value('id'),
            'away_team_id' => $event->teams()->where('name', 'Dimas')->value('id'),
            'home_score' => $home,
            'away_score' => $away,
            'sets' => $sets,
            'status' => 'finished',
            'confirmed_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Event $event, string $team): array
    {
        $rows = app(StandingService::class)->compute($event->categories->first());

        return collect($rows)->firstWhere('team.name', $team);
    }

    public function test_a_set_based_table_totals_the_points_behind_its_games(): void
    {
        $org = $this->org(User::factory()->create());

        // 2-1 in games: 21-15, 18-21, 21-19.
        $badminton = $this->event($org, 'badminton');
        $this->play($badminton, 2, 1, [
            ['home' => 21, 'away' => 15],
            ['home' => 18, 'away' => 21],
            ['home' => 21, 'away' => 19],
        ]);

        $ammar = $this->row($badminton, 'Ammar');

        // The match score is games won — the column football calls gol.
        $this->assertSame(2, $ammar['goals_for']);
        $this->assertSame(1, $ammar['goals_against']);
        // 21+18+21 = 60 against 15+21+19 = 55.
        $this->assertSame(60, $ammar['points_for']);
        $this->assertSame(55, $ammar['points_against']);
        $this->assertSame(5, $ammar['points_diff']);
        // Only a squad tie has a games tier of its own; here the games *are*
        // the match score above.
        $this->assertSame(0, $ammar['sets_for']);

        // Same scoreline in football: there are no sets to total, so the points
        // tier stays empty rather than mirroring the goals.
        $football = $this->event($org, 'football', ['participant_type' => 'team']);
        $this->play($football, 2, 1);

        $scorer = $this->row($football, 'Ammar');

        $this->assertSame(2, $scorer['goals_for']);
        $this->assertSame(0, $scorer['points_for']);
        $this->assertSame(0, $scorer['points_against']);
        $this->assertSame(0, $scorer['points_diff']);
    }

    public function test_tiebreakers_and_points_defaults_follow_the_sport(): void
    {
        $org = $this->org(User::factory()->create());

        $football = HybridConfig::fromCategory(
            $this->event($org, 'football', ['participant_type' => 'team'])->categories->first(),
        );

        // Unchanged: this is the order every existing event already runs on.
        $this->assertSame(
            ['head_to_head', 'goal_difference', 'goals_scored', 'fair_play', 'drawing_lots'],
            $football->tiebreakers,
        );
        $this->assertSame([3, 1, 0], [$football->pointsWin, $football->pointsDraw, $football->pointsLose]);

        $badminton = HybridConfig::fromCategory($this->event($org, 'badminton')->categories->first());

        // No "Selisih Gol", no "Fair Play" — badminton has neither.
        $this->assertSame(
            ['head_to_head', 'game_difference', 'rubber_points', 'games_won', 'drawing_lots'],
            $badminton->tiebreakers,
        );
        // A singles match cannot end level, so there is no draw to pay for.
        $this->assertSame([1, 0, 0], [$badminton->pointsWin, $badminton->pointsDraw, $badminton->pointsLose]);
    }

    public function test_a_squad_tie_ranks_on_partai_then_games_then_points(): void
    {
        $org = $this->org(User::factory()->create());

        $event = $this->event($org, 'badminton', [
            'participant_type' => 'team',
            'rubber_format' => [
                ['label' => 'Tunggal Putra', 'type' => 'single'],
                ['label' => 'Ganda Putra', 'type' => 'double'],
            ],
        ]);
        $category = $event->categories->first();

        $this->assertSame('rubber', $category->standingsContext());

        $config = HybridConfig::fromCategory($category);

        $this->assertSame(
            ['head_to_head', 'rubber_difference', 'rubber_games', 'rubber_points', 'drawing_lots'],
            $config->tiebreakers,
        );
        // A tie *can* finish 1-1, so a draw is still worth paying for here.
        $this->assertSame([3, 1, 0], [$config->pointsWin, $config->pointsDraw, $config->pointsLose]);
    }

    public function test_remap_translates_football_tiebreakers_saved_before_the_vocabulary_split(): void
    {
        $org = $this->org(User::factory()->create());

        // What the config card used to save for *every* sport.
        $football = ['head_to_head', 'goal_difference', 'goals_scored', 'fair_play', 'drawing_lots'];

        $badminton = $this->event($org, 'badminton', ['bracket_config' => ['tiebreakers' => $football]]);
        $squad = $this->event($org, 'badminton', [
            'participant_type' => 'team',
            'rubber_format' => [['label' => 'Tunggal Putra', 'type' => 'single']],
            'bracket_config' => ['tiebreakers' => $football, 'groups' => 2],
        ]);
        $soccer = $this->event($org, 'football', [
            'participant_type' => 'team',
            'bracket_config' => ['tiebreakers' => $football],
        ]);

        $this->artisan('standings:remap-tiebreakers')->assertSuccessful();

        // Same priorities, said in badminton's words. Fair play has no
        // equivalent — no cards to count — so it goes.
        $this->assertSame(
            ['head_to_head', 'game_difference', 'games_won', 'drawing_lots'],
            $badminton->categories->first()->fresh()->bracket_config['tiebreakers'],
        );
        $this->assertSame(
            ['head_to_head', 'rubber_difference', 'drawing_lots'],
            $squad->categories->first()->fresh()->bracket_config['tiebreakers'],
        );
        // The rest of the config rides along untouched.
        $this->assertSame(2, $squad->categories->first()->fresh()->bracket_config['groups']);

        // A goal sport was never wrong, so it is never rewritten.
        $this->assertSame($football, $soccer->categories->first()->fresh()->bracket_config['tiebreakers']);

        // And a second run has nothing left to do.
        $this->artisan('standings:remap-tiebreakers')->expectsOutputToContain('0 kategori diperbarui.');
    }

    public function test_remap_also_writes_back_the_points_that_rode_in_with_them(): void
    {
        $org = $this->org(User::factory()->create());

        // Football's defaults, on a category where a draw cannot happen.
        $event = $this->event($org, 'badminton', [
            'bracket_config' => ['points' => ['win' => 3, 'draw' => 1, 'lose' => 0], 'groups' => 2],
        ]);

        $this->artisan('standings:remap-tiebreakers')->assertSuccessful();

        $stored = $event->categories->first()->fresh()->bracket_config;

        // Leaving these behind would put a row in the database that says 3 per
        // win while every reader of it says 1.
        $this->assertSame(['win' => 1, 'draw' => 0, 'lose' => 0], $stored['points']);
        $this->assertSame(2, $stored['groups']);
        // Nothing to translate here, so no empty tiebreaker list is invented.
        $this->assertArrayNotHasKey('tiebreakers', $stored);

        $this->artisan('standings:remap-tiebreakers')->expectsOutputToContain('0 kategori diperbarui.');
    }

    public function test_a_config_saved_under_another_sport_is_translated_without_running_the_command(): void
    {
        $org = $this->org(User::factory()->create());

        // What the config card saves when the sport dropdown still shows its
        // default: the organizer picks badminton afterwards and the row keeps
        // football's words. Ranking it on head-to-head and a coin toss — which
        // is all that survives a plain filter — loses priorities they did set.
        $event = $this->event($org, 'badminton', [
            'bracket_config' => [
                'tiebreakers' => ['head_to_head', 'goal_difference', 'goals_scored', 'fair_play', 'drawing_lots'],
                'points' => ['win' => 3, 'draw' => 1, 'lose' => 0],
            ],
        ]);

        $config = HybridConfig::fromCategory($event->categories->first());

        // Same order, said in badminton's words. Fair play has no equivalent —
        // no cards to count — so it goes.
        $this->assertSame(
            ['head_to_head', 'game_difference', 'games_won', 'drawing_lots'],
            $config->tiebreakers,
        );
        // Nothing here can earn the stored draw point, so it was never chosen
        // for this category — and neither was the 3 beside it.
        $this->assertSame([1, 0, 0], [$config->pointsWin, $config->pointsDraw, $config->pointsLose]);

        // A deliberate scale is left alone: the tell is the impossible draw.
        $chosen = $this->event($org, 'badminton', [
            'bracket_config' => ['points' => ['win' => 2, 'draw' => 0, 'lose' => 0]],
        ]);
        $this->assertSame(2, HybridConfig::fromCategory($chosen->categories->first())->pointsWin);
    }

    public function test_the_api_sends_the_tiebreakers_the_table_actually_ranks_on(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);

        $event = $this->event($org, 'badminton', [
            'bracket_config' => [
                'groups' => 2,
                'tiebreakers' => ['head_to_head', 'goal_difference', 'fair_play', 'drawing_lots'],
                'points' => ['win' => 3, 'draw' => 1, 'lose' => 0],
            ],
        ]);

        // The event page prints this list under the table. Sending the raw row
        // would have it spell out "Selisih Gol" while the standings behind it
        // rank on "Selisih Game".
        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.categories.0.bracket_config.tiebreakers', [
                'head_to_head', 'game_difference', 'drawing_lots',
            ])
            ->assertJsonPath('data.categories.0.bracket_config.points', [
                'win' => 1, 'draw' => 0, 'lose' => 0,
            ])
            // Everything the organizer set that still applies rides along.
            ->assertJsonPath('data.categories.0.bracket_config.groups', 2);
    }

    public function test_an_unconfigured_category_is_still_sent_as_unconfigured(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org, 'badminton');

        // "Belum diatur" is not the same answer as "diatur dengan default" —
        // the client fills the defaults itself, from the catalog.
        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.categories.0.bracket_config', null);
    }

    public function test_editing_a_tiebreaker_keeps_the_contexts_it_belongs_to(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $option = ConfigOption::where('group', 'tiebreaker')
            ->where('key', 'game_difference')
            ->firstOrFail();

        // Renaming a row must not cost it its `applies_to` — a tiebreaker that
        // loses it starts being offered for every sport, "Selisih Game" and all.
        $this->actingAs($admin, 'api')
            ->putJson("/api/v1/admin/config-options/{$option->id}", [
                'label' => 'Selisih Game (BWF)',
                'meta' => ['comparator' => 'goal_difference', 'applies_to' => ['set']],
            ])
            ->assertOk();

        $this->assertSame(['set'], $option->fresh()->meta['applies_to']);

        Catalog::flush();
        $this->assertNotContains('game_difference', Catalog::tiebreakerKeys('goal'));
    }

    public function test_a_stored_tiebreaker_the_sport_cannot_compute_is_dropped(): void
    {
        $org = $this->org(User::factory()->create());

        // Say the event was created under football and later switched sport:
        // "Selisih Gol" has no meaning left, and keeping it would rank badminton
        // entrants on a column nobody fills.
        $event = $this->event($org, 'badminton', [
            'bracket_config' => ['tiebreakers' => ['goal_difference', 'game_difference', 'drawing_lots']],
        ]);

        $this->assertSame(
            ['game_difference', 'drawing_lots'],
            HybridConfig::fromCategory($event->categories->first())->tiebreakers,
        );
    }
}
