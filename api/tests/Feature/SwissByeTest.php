<?php

namespace Tests\Feature;

use App\Models\EventCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\StandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * An odd field means somebody sits out, and the row that says so is a fixture
 * with one side: finished, confirmed, and carrying no scoreline at all.
 *
 * Everything is asserted by *comparing* the bye against a real result on the
 * same table — a win worth the same points but with goals behind it — and by
 * comparing the byes handed out across rounds against each other. "The entrant
 * got three points" is true of a fabricated 1-0 too, and that is the shape this
 * is written to rule out.
 */
class SwissByeTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private User $owner;

    private Organization $org;

    /** @var array<string, string> name => team id */
    private array $teams = [];

    private function category(int $count = 5): EventCategory
    {
        $this->owner = User::factory()->create();
        $this->org = $this->orgFor($this->owner);
        $event = $this->eventOn($this->org, null, [
            'status' => 'ongoing',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $category = $event->categories()->create([
            'name' => 'Utama',
            'slug' => 'utama',
            'participant_type' => 'team',
            'tournament_format' => 'swiss',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        $this->teams = [];
        foreach (array_slice(['A', 'B', 'C', 'D', 'E', 'F', 'G'], 0, $count) as $name) {
            $this->teams[$name] = $event->teams()->create([
                'category_id' => $category->id,
                'name' => $name,
                'status' => 'approved',
            ])->id;
        }

        return $category;
    }

    private function url(EventCategory $category, string $suffix): string
    {
        return "/api/v1/organizations/{$this->org->id}/events/{$category->event_id}"
            ."/categories/{$category->id}/{$suffix}";
    }

    private function buildRound1(EventCategory $category): void
    {
        $this->actingAs($this->owner, 'api')
            ->postJson($this->url($category, 'schedule'))
            ->assertCreated();
    }

    private function addRound(EventCategory $category): void
    {
        $this->actingAs($this->owner, 'api')
            ->postJson($this->url($category, 'swiss/rounds'))
            ->assertCreated();
    }

    /** Settle a round 2-0 to the home side, leaving the bye row alone. */
    private function finish(EventCategory $category, int $round): void
    {
        $category->matches()->where('round', $round)->whereNotNull('away_team_id')->get()
            ->each(fn ($match) => $match->update([
                'home_score' => 2,
                'away_score' => 0,
                'status' => 'finished',
                'confirmed_at' => now(),
            ]));
    }

    /** @return array<string, array<string, mixed>> name => standings row */
    private function rows(EventCategory $category): array
    {
        $out = [];

        foreach (app(StandingService::class)->compute($category->fresh()) as $row) {
            $out[$row['team']['name']] = $row;
        }

        return $out;
    }

    private function byeTeamId(EventCategory $category, int $round): ?string
    {
        return $category->matches()
            ->where('round', $round)
            ->whereNull('away_team_id')
            ->value('home_team_id');
    }

    public function test_the_bye_row_is_a_win_with_no_scoreline_behind_it(): void
    {
        $category = $this->category(5);
        $this->buildRound1($category);

        $byeId = $this->byeTeamId($category, 1);
        $this->assertNotNull($byeId, 'An odd field must rest somebody.');

        $row = $category->matches()->where('round', 1)->whereNull('away_team_id')->first();

        // Finished and confirmed, so no gate waits on it — and both scores null,
        // so no column is fed a number nobody played for.
        $this->assertSame('finished', $row->status);
        $this->assertNotNull($row->confirmed_at);
        $this->assertNull($row->home_score);
        $this->assertNull($row->away_score);

        $this->finish($category, 1);
        $rows = $this->rows($category);

        $resting = collect($rows)->firstWhere('team.id', $byeId);
        $winner = collect($rows)->first(fn ($r) => $r['team']['id'] !== $byeId && $r['won'] === 1);

        // Same played, same win, same points as a 2-0 winner...
        $this->assertSame(1, $resting['played']);
        $this->assertSame(1, $resting['won']);
        $this->assertSame($winner['points'], $resting['points']);
        // ...and none of the goals. Compared with that winner, because "0 goals"
        // on its own is also what an empty table says.
        $this->assertSame(2, $winner['goals_for']);
        $this->assertSame(0, $resting['goals_for']);
        $this->assertSame(0, $resting['goals_against']);
    }

    public function test_the_bye_moves_around_the_field_rather_than_settling_on_one_entrant(): void
    {
        $category = $this->category(5);
        // Four rounds, one more than the three a five-entrant field derives: the
        // rule being tested is about who has rested how often, so the field has
        // to go round once more than it has entrants to rest.
        $category->update(['bracket_config' => ['swiss_rounds' => 4]]);

        $taken = [];

        $this->buildRound1($category);
        $taken[] = $this->byeTeamId($category, 1);
        $this->finish($category, 1);

        foreach ([2, 3, 4] as $round) {
            $this->addRound($category);
            $taken[] = $this->byeTeamId($category, $round);
            $this->finish($category, $round);
        }

        // Four rounds, four different entrants rested. Compared against the
        // count rather than asserted one at a time: a bye that never moved would
        // still produce four bye rows.
        $this->assertCount(4, $taken);
        $this->assertCount(4, array_unique($taken));

        // And the fairness rule stated as the rule itself, over the whole field
        // rather than over the rows that exist: an entrant nobody rested counts
        // zero, which is the side of max − min ≤ 1 a count of bye rows cannot see.
        $counts = array_count_values($taken);
        $perTeam = array_map(fn (string $id) => $counts[$id] ?? 0, array_values($this->teams));

        $this->assertLessThanOrEqual(1, max($perTeam) - min($perTeam));
    }

    public function test_a_rested_entrant_is_not_pending_forever(): void
    {
        $category = $this->category(5);
        $this->buildRound1($category);
        $this->finish($category, 1);

        // The bye row matches "not played yet" on a naive reading — finished, but
        // with no scores — so the gate has to exclude it by its missing opponent.
        $state = $this->actingAs($this->owner, 'api')
            ->getJson($this->url($category, 'swiss'))->json('data');

        $this->assertSame(0, $state['round_matches_pending']);
        $this->assertTrue($state['can_add_round']);
        // Compared with the total, which counts the bye: the round is 3 fixtures,
        // two of them real.
        $this->assertSame(3, $state['round_matches_total']);
    }

    public function test_dropping_a_round_takes_its_bye_points_with_it(): void
    {
        $category = $this->category(5);
        $this->buildRound1($category);
        $this->finish($category, 1);

        $this->addRound($category);

        $byeId = $this->byeTeamId($category, 2);
        $this->assertNotNull($byeId);

        $before = collect($this->rows($category))->firstWhere('team.id', $byeId);

        $this->actingAs($this->owner, 'api')
            ->deleteJson($this->url($category, 'swiss/rounds/last'))
            ->assertOk();

        $after = collect($this->rows($category))->firstWhere('team.id', $byeId);

        // The round is gone, and so is the win it paid for. Compared before and
        // after on the same entrant: asserting the fixtures were deleted says
        // nothing about the table that was reading them.
        $this->assertSame($before['played'] - 1, $after['played']);
        $this->assertSame($before['won'] - 1, $after['won']);
        $this->assertSame($before['points'] - 3, $after['points']);
        $this->assertSame(0, $category->matches()->where('round', 2)->count());
    }

    public function test_an_even_field_rests_nobody(): void
    {
        // The comparison the whole file rests on: the same code path on an even
        // field writes no bye row at all, so none of the above is an artefact of
        // a bye being handed out unconditionally.
        $even = $this->category(6);
        $this->buildRound1($even);

        $this->assertSame(3, $even->matches()->where('round', 1)->count());
        $this->assertSame(0, $even->matches()->where('round', 1)->whereNull('away_team_id')->count());
    }
}
