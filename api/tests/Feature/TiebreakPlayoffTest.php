<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlayerMatchStat;
use App\Models\User;
use App\Services\StandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The last tiebreaker before the lot: an extra tie played for no reason other
 * than to separate two teams the table could not.
 *
 * The whole feature rests on one invariant — a decider moves *rows*, never
 * numbers. Its goals are not goals, its cards are not cards, and nobody played
 * an extra match as far as the table is concerned. So the central test here
 * does not assert "the winner is first"; it snapshots every column before the
 * decider and demands that all of them still read the same afterwards. Asserting
 * the new order alone would pass just as happily if the decider were quietly
 * being counted as a group fixture.
 */
class TiebreakPlayoffTest extends TestCase
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
     * A football league whose two entrants are indistinguishable: they drew, so
     * every column and every criterion above the decider reads the same for
     * both. This is the state the feature exists for.
     *
     * @param  array<string, mixed>  $category  overrides for the category row
     */
    private function deadHeat(Organization $org, array $category = []): Event
    {
        $event = $org->events()->create([
            'name' => 'Piala Nusantara',
            'slug' => 'piala-'.uniqid(),
            'sport_type' => 'football',
            'status' => 'ongoing',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $model = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'participant_type' => 'team',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
            ...$category,
        ]);

        foreach (['Persija', 'Persib'] as $name) {
            $team = $event->teams()->create([
                'category_id' => $model->id,
                'name' => $name,
                'status' => 'approved',
            ]);

            $team->players()->create(['full_name' => $name.' 1']);
        }

        $event->matches()->create([
            'category_id' => $model->id,
            'round' => 1,
            'leg' => 1,
            'order' => 1,
            'home_team_id' => $event->teams()->where('name', 'Persija')->value('id'),
            'away_team_id' => $event->teams()->where('name', 'Persib')->value('id'),
            'home_score' => 1,
            'away_score' => 1,
            'status' => 'finished',
            'confirmed_at' => now(),
        ]);

        return $event->load('categories');
    }

    /**
     * The table, keyed by team name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function table(Event $event): array
    {
        $rows = app(StandingService::class)->compute($event->categories->first()->fresh());

        return collect($rows)->keyBy('team.name')->all();
    }

    private function matchesUrl(Organization $org, Event $event): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/matches";
    }

    public function test_a_dead_heat_is_flagged_and_a_decider_reorders_it_without_touching_a_single_number(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->deadHeat($org);

        $before = $this->table($event);

        // Nothing in the config could separate them, so both places are the
        // lot's guess and the organizer is told so.
        $this->assertTrue($before['Persija']['needs_decider']);
        $this->assertTrue($before['Persib']['needs_decider']);

        $matchId = $this->actingAs($user, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $event->teams()->where('name', 'Persija')->value('id'),
                'away_team_id' => $event->teams()->where('name', 'Persib')->value('id'),
                'stage' => 'playoff',
            ])
            ->assertCreated()
            ->assertJsonPath('data.stage', 'playoff')
            ->json('data.id');

        // Level again, so the shootout decides it.
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$matchId}", [
                'status' => 'finished',
                'home_score' => 1,
                'away_score' => 1,
                'home_penalty' => 4,
                'away_penalty' => 3,
            ])
            ->assertOk();

        $after = $this->table($event);

        // What the decider is allowed to change: the order, and the flag that
        // asked for it.
        $this->assertSame(1, $after['Persija']['rank']);
        $this->assertSame(2, $after['Persib']['rank']);
        $this->assertFalse($after['Persija']['needs_decider']);
        $this->assertFalse($after['Persib']['needs_decider']);

        // And what it may not: everything else. A decider counted as an
        // ordinary fixture would show up here as a second appearance, a win,
        // three more points, an extra goal — any one of which is the bug.
        foreach (['Persija', 'Persib'] as $name) {
            $this->assertSame(
                array_diff_key($before[$name], array_flip(['rank', 'needs_decider'])),
                array_diff_key($after[$name], array_flip(['rank', 'needs_decider'])),
                "Laga penentuan mengubah angka klasemen {$name}.",
            );
        }

        // Belt and braces on the two that matter most, in case the row shape
        // ever changes and the diff above stops covering them.
        $this->assertSame(1, $after['Persija']['played']);
        $this->assertSame(1, $after['Persija']['points']);
    }

    public function test_a_decider_only_counts_once_it_is_confirmed(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->deadHeat($org);

        $match = $event->matches()->create([
            'category_id' => $event->categories->first()->id,
            'stage' => 'playoff',
            'round' => 1,
            'leg' => 1,
            'order' => 1,
            'home_team_id' => $event->teams()->where('name', 'Persib')->value('id'),
            'away_team_id' => $event->teams()->where('name', 'Persija')->value('id'),
            'home_score' => 2,
            'away_score' => 0,
            'status' => 'finished',
        ]);

        // An operator's entry that no admin has signed off yet ranks nobody —
        // same rule the rest of the table already runs on.
        $this->assertTrue($this->table($event)['Persija']['needs_decider']);

        $match->update(['confirmed_at' => now()]);

        $this->assertSame(1, $this->table($event)['Persib']['rank']);
        $this->assertFalse($this->table($event)['Persib']['needs_decider']);
    }

    public function test_cards_shown_in_a_decider_do_not_reach_fair_play(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        // Compared, not asserted alone: the same card in a group match has to
        // move fair play, or "the decider's card did nothing" proves only that
        // fair play is broken everywhere.
        $counted = $this->deadHeat($org);
        $this->book($counted, 'Persija', $counted->matches()->first());

        $this->assertSame(1, $this->table($counted)['Persija']['fair_play']);
        // …and it separates them, so no decider is owed any more.
        $this->assertSame(1, $this->table($counted)['Persib']['rank']);
        $this->assertFalse($this->table($counted)['Persib']['needs_decider']);

        $ignored = $this->deadHeat($org);
        $decider = $ignored->matches()->create([
            'category_id' => $ignored->categories->first()->id,
            'stage' => 'playoff',
            'round' => 1,
            'leg' => 1,
            'order' => 1,
            'home_team_id' => $ignored->teams()->where('name', 'Persija')->value('id'),
            'away_team_id' => $ignored->teams()->where('name', 'Persib')->value('id'),
            'status' => 'scheduled',
        ]);
        $this->book($ignored, 'Persija', $decider);

        // A card in the decider would feed back into the criterion the decider
        // was scheduled *because of*, reordering the pair before its own result
        // is even read.
        $this->assertSame(0, $this->table($ignored)['Persija']['fair_play']);
        $this->assertTrue($this->table($ignored)['Persija']['needs_decider']);
    }

    public function test_a_level_decider_is_refused_until_it_names_a_winner(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->deadHeat($org);

        $matchId = $this->actingAs($user, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $event->teams()->where('name', 'Persija')->value('id'),
                'away_team_id' => $event->teams()->where('name', 'Persib')->value('id'),
                'stage' => 'playoff',
            ])
            ->assertCreated()
            ->json('data.id');

        $url = "/api/v1/organizations/{$org->id}/matches/{$matchId}";

        $this->actingAs($user, 'api')
            ->patchJson($url, ['status' => 'finished', 'home_score' => 1, 'away_score' => 1])
            ->assertStatus(422)
            ->assertJsonPath('errors.home_penalty.0', 'Skor adu penalti wajib diisi.');

        $this->actingAs($user, 'api')
            ->patchJson($url, [
                'status' => 'finished', 'home_score' => 1, 'away_score' => 1,
                'home_penalty' => 4, 'away_penalty' => 4,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.home_penalty.0', 'Harus ada pemenang.');
    }

    public function test_a_knockout_category_has_no_table_to_break_a_tie_in(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->deadHeat($org, ['tournament_format' => 'knockout_single']);

        $this->actingAs($user, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $event->teams()->where('name', 'Persija')->value('id'),
                'away_team_id' => $event->teams()->where('name', 'Persib')->value('id'),
                'stage' => 'playoff',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.stage.0', 'Laga penentuan hanya untuk format liga atau grup.');
    }

    public function test_a_hybrid_decider_has_to_say_which_group_it_settles(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->deadHeat($org, [
            'tournament_format' => 'hybrid',
            'bracket_config' => ['groups' => 2, 'teams_per_group' => 2],
        ]);

        $event->teams()->update(['group_name' => 'A']);

        $this->actingAs($user, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $event->teams()->where('name', 'Persija')->value('id'),
                'away_team_id' => $event->teams()->where('name', 'Persib')->value('id'),
                'stage' => 'playoff',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.group_name.0', 'Grup wajib diisi untuk laga penentuan.');

        // Named, it is filed under that group for display — but the stage is
        // what keeps it out of the group's table.
        $this->actingAs($user, 'api')
            ->postJson($this->matchesUrl($org, $event), [
                'home_team_id' => $event->teams()->where('name', 'Persija')->value('id'),
                'away_team_id' => $event->teams()->where('name', 'Persib')->value('id'),
                'group_name' => 'A',
                'stage' => 'playoff',
            ])
            ->assertCreated()
            ->assertJsonPath('data.stage', 'playoff')
            ->assertJsonPath('data.group_name', 'A');
    }

    public function test_the_flag_stays_down_for_an_organizer_who_removed_the_rule(): void
    {
        $org = $this->org(User::factory()->create());

        // The mark is an invitation to play a decider. Without the rule in the
        // order, playing one would change nothing — so it must not be offered.
        $event = $this->deadHeat($org, [
            'bracket_config' => ['tiebreakers' => ['head_to_head', 'goal_difference', 'drawing_lots']],
        ]);

        $this->assertFalse($this->table($event)['Persija']['needs_decider']);
    }

    /** A yellow card for that team's only player, in the given match. */
    private function book(Event $event, string $team, GameMatch $match): void
    {
        $teamModel = $event->teams()->where('name', $team)->first();

        PlayerMatchStat::create([
            'match_id' => $match->id,
            'team_id' => $teamModel->id,
            'player_id' => $teamModel->players()->value('id'),
            'stat_key' => 'yellow_cards',
            'value' => 1,
        ]);
    }
}
