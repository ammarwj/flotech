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
 * Moving a fixture between scheduled / ongoing / cancelled.
 *
 * The endpoint exists because updateResult() rebuilds its payload from scratch
 * and nulls whatever the request omitted — most of what is asserted here is
 * that a status change leaves the scoreline alone, and that withdrawing a
 * confirmed result also withdraws the team it sent into the next round.
 */
class MatchStatusTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    /** @param 'knockout_single'|'league' $format */
    private function event(Organization $org, string $format = 'knockout_single', int $teams = 8): Event
    {
        $event = $org->events()->create([
            'name' => 'Status Cup',
            'slug' => 'status-cup-'.uniqid(),
            'sport_type' => 'mini_soccer',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => $format,
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        foreach (range(1, $teams) as $i) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => 'Team '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'status' => 'approved',
                'contact_name' => 'PIC',
                'contact_phone' => '0800',
            ]);
        }

        return $event->load('categories');
    }

    private function generate(User $user, Organization $org, Event $event): void
    {
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$event->categories->first()->id}/schedule")
            ->assertCreated();
    }

    private function slot(Event $event, int $round, int $order): GameMatch
    {
        return $event->matches()->where('round', $round)->where('order', $order)->firstOrFail();
    }

    private function statusUrl(Organization $org, GameMatch $match): string
    {
        return "/api/v1/organizations/{$org->id}/matches/{$match->id}/status";
    }

    /** Play a 2-1 home win and confirm it, so the winner advances. */
    private function playAndConfirm(User $user, Organization $org, GameMatch $match): void
    {
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}", [
                'status' => 'finished', 'home_score' => 2, 'away_score' => 1,
            ])
            ->assertOk();

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}/confirm", ['confirmed' => true])
            ->assertOk();
    }

    public function test_marking_a_match_ongoing_keeps_its_score(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $tie = $this->slot($event, 1, 0);
        $tie->update(['home_score' => 1, 'away_score' => 0, 'sets' => [['home' => 25, 'away' => 20]]]);

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $tie), ['status' => 'ongoing'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ongoing');

        // The whole reason this endpoint exists rather than reusing
        // updateResult, which would have nulled all three.
        $tie->refresh();
        $this->assertSame(1, $tie->home_score);
        $this->assertSame(0, $tie->away_score);
        $this->assertSame([['home' => 25, 'away' => 20]], $tie->sets);
    }

    public function test_status_endpoint_cannot_finish_a_match(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $tie = $this->slot($event, 1, 0);

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $tie), ['status' => 'finished'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('scheduled', $tie->fresh()->status);
    }

    public function test_status_endpoint_rejects_an_unknown_status(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $this->slot($event, 1, 0)), ['status' => 'postponed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_cancelling_a_confirmed_knockout_match_clears_what_it_fed_downstream(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $tie = $this->slot($event, 1, 0);
        $this->playAndConfirm($user, $org, $tie);

        $parent = $this->slot($event, 2, 0);
        $this->assertNotNull($parent->home_team_id, 'the winner should have advanced');

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $tie), ['status' => 'cancelled'])
            ->assertOk();

        $parent->refresh();
        $this->assertNull($parent->home_team_id);
        $this->assertNull($parent->home_score);
        $this->assertSame('scheduled', $parent->status);
        $this->assertNull($parent->confirmed_at);

        // The cancelled fixture keeps its scoreline, so an accidental cancel is
        // fully reversible; only the confirmation is withdrawn.
        $tie->refresh();
        $this->assertSame('cancelled', $tie->status);
        $this->assertNull($tie->confirmed_at);
        $this->assertSame(2, $tie->home_score);
    }

    public function test_cancelling_an_unconfirmed_match_leaves_downstream_alone(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        // Only the sibling is settled, so it — and nothing else — sits upstairs.
        $this->playAndConfirm($user, $org, $this->slot($event, 1, 1));
        $parent = $this->slot($event, 2, 0);
        $fromSibling = $parent->away_team_id;
        $this->assertNotNull($fromSibling);

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $this->slot($event, 1, 0)), ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSame(
            $fromSibling,
            $parent->fresh()->away_team_id,
            'the other half of the bracket must not be touched',
        );
    }

    public function test_moving_a_confirmed_match_back_to_ongoing_unconfirms_it(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $tie = $this->slot($event, 1, 0);
        $this->playAndConfirm($user, $org, $tie);

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $tie), ['status' => 'ongoing'])
            ->assertOk()
            ->assertJsonPath('data.confirmed', false);

        // confirmed_at !== null must imply status === 'finished'.
        $this->assertNull($tie->fresh()->confirmed_at);
    }

    public function test_cancelling_a_confirmed_match_drops_it_from_standings(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org, format: 'league', teams: 4);
        $this->generate($user, $org, $event);
        $catId = $event->categories->first()->id;

        $matches = $event->matches()->orderBy('round')->orderBy('order')->take(2)->get();
        foreach ($matches as $m) {
            $this->playAndConfirm($user, $org, $m);
        }

        $winner = $matches[0]->home_team_id;

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $matches[0]), ['status' => 'cancelled'])
            ->assertOk();

        $rows = $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$catId}/standings")
            ->assertOk()
            ->json('data');

        $byTeam = collect($rows)->keyBy(fn ($row) => $row['team']['id']);
        $this->assertSame(0, $byTeam[$winner]['played'], 'a cancelled result must leave the table');
        $this->assertSame(0, $byTeam[$winner]['points']);
    }

    public function test_reactivating_a_cancelled_match_restores_it_to_scheduled(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $tie = $this->slot($event, 1, 0);

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $tie), ['status' => 'cancelled'])
            ->assertOk();

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $tie), ['status' => 'scheduled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');
    }

    public function test_setting_the_same_status_twice_is_a_no_op(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $this->generate($user, $org, $event);

        $tie = $this->slot($event, 1, 0);
        $this->playAndConfirm($user, $org, $tie);
        // The sibling's winner also sits in the round-2 tie.
        $this->playAndConfirm($user, $org, $this->slot($event, 1, 1));

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $tie), ['status' => 'cancelled'])
            ->assertOk();

        $parent = $this->slot($event, 2, 0);
        $survivor = $parent->away_team_id;
        $this->assertNotNull($survivor);

        // A second identical call must not walk the bracket again — doing so
        // would clear the slot the sibling legitimately holds.
        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $tie), ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSame($survivor, $parent->fresh()->away_team_id);
    }

    public function test_status_endpoint_is_scoped_to_the_organization(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $this->generate($owner, $org, $event);

        $intruder = User::factory()->create();
        $otherOrg = $this->org($intruder);

        $tie = $this->slot($event, 1, 0);

        $this->actingAs($intruder, 'api')
            ->patchJson("/api/v1/organizations/{$otherOrg->id}/matches/{$tie->id}/status", ['status' => 'cancelled'])
            ->assertNotFound();

        $this->assertSame('scheduled', $tie->fresh()->status);
    }
}
