<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Redrawing a knockout bracket.
 *
 * A single-elimination bracket is generated from `orderBy('name')`, so out of
 * the box it is an alphabetical list rather than a draw — Team 01 always meets
 * Team 02. This endpoint is the draw, and it works *in place*: the fixtures,
 * their kickoff times and their courts survive it, only the occupants move.
 *
 * The two things worth breaking are the two asserted hardest: that a kickoff
 * the organizer typed is still there afterwards, and that a bracket with a
 * result in it refuses to be redrawn out from under that result.
 */
class BracketShuffleTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    /** A badminton-shaped event: a knockout category with $teams entrants. */
    private function knockoutEvent(Organization $org, int $teams = 8, string $format = 'knockout_single'): Event
    {
        $event = $org->events()->create([
            'name' => 'Kejurnas Bulutangkis',
            'slug' => 'kejurnas-'.uniqid(),
            'sport_type' => 'badminton',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $category = $event->categories()->create([
            'name' => 'Tunggal Putra',
            'slug' => 'tunggal-putra',
            'participant_type' => $format === 'knockout_single' ? 'single' : 'team',
            'tournament_format' => $format,
            'bracket_config' => $format === 'hybrid'
                ? ['groups' => 2, 'teams_per_group' => 4, 'qualification' => ['top_per_group' => 2]]
                : null,
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        foreach (range(1, $teams) as $i) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => 'Pemain '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'status' => 'approved',
                'contact_name' => 'PIC',
                'contact_phone' => '0800',
            ]);
        }

        return $event->load('categories');
    }

    private function categoryUrl(Organization $org, Event $event, string $path): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$event->id}"
            ."/categories/{$event->categories->first()->id}".$path;
    }

    private function generate(User $user, Organization $org, Event $event): void
    {
        $this->actingAs($user, 'api')
            ->postJson($this->categoryUrl($org, $event, '/schedule'))
            ->assertCreated();
    }

    private function shuffle(User $user, Organization $org, Event $event)
    {
        return $this->actingAs($user, 'api')
            ->postJson($this->categoryUrl($org, $event, '/bracket/shuffle'));
    }

    /** First-round slots as `order => [home, away]`. */
    private function firstRound(Event $event): array
    {
        return $event->matches()
            ->where('round', 1)
            ->orderBy('order')
            ->get()
            ->mapWithKeys(fn ($m) => [$m->order => [$m->home_team_id, $m->away_team_id]])
            ->all();
    }

    // ---------------------------------------------------------------------

    public function test_it_keeps_every_fixture_kickoff_and_team(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);

        // One tie timed by hand, the way an organizer books a show court.
        $opener = $event->matches()->where('round', 1)->where('order', 0)->firstOrFail();
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$opener->id}/schedule", [
                'scheduled_at' => '2026-08-05T19:30:00+07:00',
                'venue' => 'Lapangan 1',
            ])
            ->assertOk();

        $before = $event->matches()->orderBy('round')->orderBy('order')->get();
        $beforeTeams = collect($this->firstRound($event))->flatten()->filter()->sort()->values();

        $this->shuffle($user, $org, $event)->assertOk();

        $after = $event->matches()->orderBy('round')->orderBy('order')->get();

        // Nothing created, nothing deleted: this is a redraw, not a rebuild.
        $this->assertSame($before->pluck('id')->all(), $after->pluck('id')->all());

        // Every kickoff and court survives — the reason this is done in place
        // instead of regenerating, which would reset them to the allocator's
        // defaults.
        $this->assertSame(
            $before->pluck('scheduled_at')->map(fn ($d) => (string) $d)->all(),
            $after->pluck('scheduled_at')->map(fn ($d) => (string) $d)->all(),
        );
        $this->assertSame($before->pluck('venue')->all(), $after->pluck('venue')->all());

        // The same field, redrawn — nobody added, nobody dropped, nobody twice.
        $afterTeams = collect($this->firstRound($event))->flatten()->filter()->sort()->values();
        $this->assertSame($beforeTeams->all(), $afterTeams->all());
        $this->assertSame(8, $afterTeams->unique()->count());
    }

    public function test_it_actually_redraws(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org);
        $this->generate($user, $org, $event);

        $original = $this->firstRound($event);

        // A shuffle may legitimately land on the arrangement it started from, so
        // one identical draw proves nothing. Ten in a row would need every one
        // of them to hit 1-in-8! — if none differs, it is not shuffling.
        $moved = false;
        for ($i = 0; $i < 10 && ! $moved; $i++) {
            $this->shuffle($user, $org, $event)->assertOk();
            $moved = $this->firstRound($event) !== $original;
        }

        $this->assertTrue($moved, 'the draw never changed in ten attempts');
    }

    public function test_byes_are_redrawn_and_still_walk_over(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        // Six entrants in a bracket of eight: two ties are byes.
        $event = $this->knockoutEvent($org, teams: 6);
        $this->generate($user, $org, $event);

        $byeCount = fn () => collect($this->firstRound($event))
            ->filter(fn ($pair) => $pair[0] !== null && $pair[1] === null)
            ->count();

        $this->assertSame(2, $byeCount());

        $this->shuffle($user, $org, $event)->assertOk();

        // The bracket keeps its shape — two byes, in the same slots — while who
        // receives them is part of what gets redrawn.
        $this->assertSame(2, $byeCount());

        // A walkover that never walks over strands its slot forever, so the
        // advancement has to be re-run, not merely left alone.
        foreach ($event->matches()->where('round', 1)->get() as $slot) {
            if ($slot->home_team_id !== null && $slot->away_team_id === null) {
                $this->assertSame('finished', $slot->status);
                $this->assertNotNull($slot->confirmed_at);

                $parent = $event->matches()
                    ->where('round', 2)
                    ->where('order', intdiv($slot->order, 2))
                    ->firstOrFail();

                $this->assertSame(
                    $slot->home_team_id,
                    $slot->order % 2 === 0 ? $parent->home_team_id : $parent->away_team_id,
                );
            }
        }
    }

    public function test_later_rounds_are_emptied(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, teams: 6);
        $this->generate($user, $org, $event);

        // The byes have already pushed two teams into round two.
        $this->assertGreaterThan(
            0,
            $event->matches()->where('round', 2)->whereNotNull('home_team_id')->count()
                + $event->matches()->where('round', 2)->whereNotNull('away_team_id')->count(),
        );

        $this->shuffle($user, $org, $event)->assertOk();

        // Round three onwards holds nobody: every first-round slot changed
        // hands, so nothing that arrived upstairs by the old draw still belongs.
        foreach ($event->matches()->where('round', '>', 2)->get() as $m) {
            $this->assertNull($m->home_team_id);
            $this->assertNull($m->away_team_id);
            $this->assertSame('scheduled', $m->status);
            $this->assertNull($m->confirmed_at);
        }
    }

    public function test_it_refuses_once_a_result_has_been_entered(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, teams: 4);
        $this->generate($user, $org, $event);

        $opener = $event->matches()->where('round', 1)->where('order', 0)->firstOrFail();

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$opener->id}", [
                'status' => 'finished',
                'sets' => [['home' => 21, 'away' => 15], ['home' => 21, 'away' => 18]],
            ])
            ->assertOk();

        $before = $this->firstRound($event);

        $this->shuffle($user, $org, $event)
            ->assertStatus(422)
            ->assertJsonPath('errors.feature', 'bracket_shuffle');

        $this->assertSame($before, $this->firstRound($event), 'a refused draw changes nothing');
    }

    public function test_a_hybrid_bracket_is_not_shuffled(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, format: 'hybrid');

        // Its bracket is seeded from the group tables — a group winner meets
        // another group's runner-up — and randomising that throws the meaning of
        // those places away.
        $this->shuffle($user, $org, $event)
            ->assertStatus(422)
            ->assertJsonPath('errors.feature', 'bracket_shuffle');
    }

    public function test_a_league_has_no_bracket_to_shuffle(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->knockoutEvent($org, format: 'league');

        $this->shuffle($user, $org, $event)->assertStatus(422);
    }
}
