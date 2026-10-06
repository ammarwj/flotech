<?php

namespace Tests\Feature;

use App\Models\EventCategory;
use App\Models\Organization;
use App\Models\User;
use App\Models\StandingAdjustment;
use App\Services\StandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The ledger endpoints behind the standings table — see
 * StandingAdjustmentController for why it is append-and-remove rather than a
 * full-list PUT, and why there is no update action.
 *
 * The service-level invariants (what an adjustment may and may not move) live in
 * PointAdjustmentTest; everything here is about the doors.
 */
class PointAdjustmentApiTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /** @var array<string, string> name => team id */
    private array $teams = [];

    private Organization $org;

    /**
     * @param  array<int, string>  $names
     */
    private function category(Organization $org, array $names = ['Arema', 'Bali']): EventCategory
    {
        $event = $this->eventOn($org, null, ['sport_type' => 'football', 'status' => 'ongoing']);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum-'.uniqid(),
            'participant_type' => 'team',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        foreach ($names as $name) {
            $this->teams[$name] = $event->teams()->create([
                'category_id' => $category->id,
                'name' => $name,
                'status' => 'approved',
            ])->id;
        }

        return $category;
    }

    private function url(EventCategory $category): string
    {
        $org = $category->event->organization_id;

        return "/api/v1/organizations/{$org}/events/{$category->event_id}/categories/{$category->id}/adjustments";
    }

    /**
     * Three actors, one payload. The operator row is the only thing that
     * distinguishes this door from a money surface: asserting the owner
     * succeeds passes identically under `org.admin`. The outsider row is the
     * only thing proving `tenant` is doing anything at all.
     */
    public function test_an_operator_can_append_an_adjustment_but_an_outsider_cannot(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $category = $this->category($org);
        $url = $this->url($category->load('event'));

        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $payload = fn (int $points) => [
            'team_id' => $this->teams['Arema'],
            'points' => $points,
            'reason' => 'Suporter lengkap',
        ];

        $this->actingAs($owner, 'api')->postJson($url, $payload(2))->assertCreated();

        // The decision this feature was given: whoever runs the table can type
        // the two points the organizer told them to give.
        $this->actingAs($operator, 'api')
            ->postJson($url, $payload(1))
            ->assertCreated()
            ->assertJsonPath('data.points', 1)
            ->assertJsonPath('data.created_by_name', $operator->full_name);

        // 403, not 404: `tenant` resolves the org fine and then says you are
        // not a member of it.
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson($url, $payload(2))
            ->assertStatus(403);

        $this->assertSame(3, $this->adjustmentOf($category, 'Arema'));
    }

    /**
     * The structural argument against a full-list PUT, made executable: two
     * entries from two people, one removed, the other untouched with its own
     * author still on it. A test that only ever adds rows cannot see the
     * difference — under a sync endpoint the stale client deletes the entry it
     * never saw, silently.
     */
    public function test_removing_one_entry_leaves_the_others_standing(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $category = $this->category($org);
        $url = $this->url($category->load('event'));

        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $mine = $this->actingAs($owner, 'api')->postJson($url, [
            'team_id' => $this->teams['Arema'], 'points' => 2, 'reason' => 'Suporter lengkap',
        ])->assertCreated()->json('data.id');

        $theirs = $this->actingAs($operator, 'api')->postJson($url, [
            'team_id' => $this->teams['Arema'], 'points' => 1, 'reason' => 'Suporter sebagian',
        ])->assertCreated()->json('data.id');

        $this->actingAs($owner, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/adjustments/{$mine}")
            ->assertOk();

        $this->assertDatabaseMissing('standing_adjustments', ['id' => $mine]);
        $this->assertDatabaseHas('standing_adjustments', [
            'id' => $theirs,
            'points' => 1,
            'created_by' => $operator->id,
        ]);

        $this->assertSame(1, $this->adjustmentOf($category, 'Arema'));
    }

    /**
     * A row filed against a team of another category would sum into a table that
     * team does not appear in — invisible on every screen, findable only in the
     * database. Compared against the same team posted to its own category,
     * because the happy path passes with no ownership check at all.
     */
    public function test_an_adjustment_cannot_be_filed_against_a_team_of_another_category(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $mine = $this->category($org);
        $sibling = $mine->event->categories()->create([
            'name' => 'U17',
            'slug' => 'u17-'.uniqid(),
            'participant_type' => 'team',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 1,
        ]);
        $sibling->setRelation('event', $mine->event);

        $payload = ['team_id' => $this->teams['Arema'], 'points' => 2, 'reason' => 'Suporter lengkap'];

        $this->actingAs($owner, 'api')
            ->postJson($this->url($mine->load('event')), $payload)
            ->assertCreated();

        $this->actingAs($owner, 'api')
            ->postJson($this->url($sibling), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('team_id');
    }

    /**
     * A zero row moves no total and says nothing a reason alone could not, so it
     * is refused. Compared against a negative one, because `required|integer`
     * accepts 0 on its own and the difference between the two *is* the decision.
     */
    public function test_a_zero_adjustment_is_refused_but_a_negative_one_is_not(): void
    {
        $owner = User::factory()->create();
        $category = $this->category($this->orgFor($owner));
        $url = $this->url($category->load('event'));

        $this->actingAs($owner, 'api')
            ->postJson($url, ['team_id' => $this->teams['Arema'], 'points' => 0, 'reason' => 'Tidak apa-apa'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('points');

        $this->actingAs($owner, 'api')
            ->postJson($url, ['team_id' => $this->teams['Arema'], 'points' => -3, 'reason' => 'Sanksi walkout'])
            ->assertCreated()
            ->assertJsonPath('data.points', -3);

        // And the reason cannot be skipped: this number is published.
        $this->actingAs($owner, 'api')
            ->postJson($url, ['team_id' => $this->teams['Arema'], 'points' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    /**
     * The endpoint half of "a disqualified team keeps its ledger": the table
     * drops the row (asserted in PointAdjustmentTest), but the list must not.
     * Without this the entry exists, counts for nothing, is invisible, cannot be
     * deleted, and quietly returns the moment the team is approved again.
     */
    public function test_the_list_keeps_an_entry_whose_team_is_no_longer_approved(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $category = $this->category($org);
        $url = $this->url($category->load('event'));

        $id = $this->actingAs($owner, 'api')->postJson($url, [
            'team_id' => $this->teams['Bali'], 'points' => 5, 'reason' => 'Suporter lengkap',
        ])->assertCreated()->json('data.id');

        $category->teams()->whereKey($this->teams['Bali'])->update(['status' => 'disqualified']);

        $this->actingAs($owner, 'api')
            ->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.team_name', 'Bali');

        // Gone from the table it no longer belongs to, though.
        $rows = app(StandingService::class)->compute($category->fresh());
        $this->assertNotContains('Bali', array_column(array_column($rows, 'team'), 'name'));

        // And still deletable, which is the point of it staying listed.
        $this->actingAs($owner, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/adjustments/{$id}")
            ->assertOk();
    }

    /** The published total for one team, read back off the table. */
    private function adjustmentOf(EventCategory $category, string $team): int
    {
        $rows = app(StandingService::class)->compute($category->fresh());

        return collect($rows)->firstWhere('team.name', $team)['adjustment'];
    }
}
