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
 * Player stats of a single fixture, read from the public event page. No auth —
 * the org/event slug pair in the URL is the whole of the authorization, so the
 * match must be proven to belong to that event.
 */
class PublicMatchStatsTest extends TestCase
{
    use RefreshDatabase;

    private function org(): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        return Organization::create([
            'name' => 'Org',
            'slug' => 'org-'.uniqid(),
            'owner_id' => User::factory()->create()->id,
            'plan_id' => $plan->id,
        ]);
    }

    private function event(Organization $org, string $status = 'open'): Event
    {
        $event = $org->events()->create([
            'name' => 'Liga Publik',
            'slug' => 'liga-'.uniqid(),
            'sport_type' => 'mini_soccer',
            'status' => $status,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
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

    /** A finished fixture between two teams, each with a two-player squad. */
    private function playedMatch(Event $event): GameMatch
    {
        $category = $event->categories->first();

        $teams = collect(['Elang FC', 'Garuda FC'])->map(fn ($name) => $event->teams()->create([
            'category_id' => $category->id,
            'name' => $name,
            'status' => 'approved',
            'contact_name' => 'PIC',
            'contact_phone' => '0800',
        ]));

        foreach ($teams as $team) {
            $team->players()->createMany([
                // Named so that alphabetical order is the *opposite* of goal
                // order below — otherwise the sort assertion proves nothing.
                ['full_name' => $team->name.' Anwar', 'jersey_number' => '9'],
                ['full_name' => $team->name.' Zulkifli', 'jersey_number' => '17'],
                ['full_name' => $team->name.' Cadangan', 'jersey_number' => '21'],
            ]);
        }

        return GameMatch::create([
            'event_id' => $event->id,
            'category_id' => $category->id,
            'round' => 1,
            'order' => 0,
            'leg' => 1,
            'home_team_id' => $teams[0]->id,
            'away_team_id' => $teams[1]->id,
            'home_score' => 2,
            'away_score' => 0,
            'status' => 'finished',
        ]);
    }

    public function test_match_stats_are_public_and_carry_player_names(): void
    {
        $org = $this->org();
        $event = $this->event($org);
        $match = $this->playedMatch($event);

        $home = Team::find($match->home_team_id);
        $anwar = $home->players()->where('jersey_number', '9')->first();
        $zulkifli = $home->players()->where('jersey_number', '17')->first();

        $match->stats()->createMany([
            ['team_id' => $home->id, 'player_id' => $anwar->id, 'stat_key' => 'goals', 'value' => 1],
            ['team_id' => $home->id, 'player_id' => $anwar->id, 'stat_key' => 'assists', 'value' => 1],
            ['team_id' => $home->id, 'player_id' => $zulkifli->id, 'stat_key' => 'goals', 'value' => 2],
        ]);

        // No actingAs anywhere: this has to work for a logged-out visitor.
        $data = $this->getJson("/api/v1/public/events/{$org->slug}/{$event->slug}/matches/{$match->id}/stats")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data['columns'], 'stat columns should come from the sport catalog');

        $players = $data['home_team']['players'];
        $this->assertCount(2, $players, 'the third player recorded nothing and must be left out');

        // Top scorer first, even though he loses on alphabetical order.
        $this->assertSame($zulkifli->full_name, $players[0]['full_name']);
        $this->assertSame(2, $players[0]['stats']['goals']);

        $this->assertSame($anwar->full_name, $players[1]['full_name']);
        $this->assertSame('9', $players[1]['jersey_number']);
        $this->assertSame(1, $players[1]['stats']['goals']);
        $this->assertSame(1, $players[1]['stats']['assists']);

        // The other side played but recorded nothing — present, just empty.
        $this->assertSame('Garuda FC', $data['away_team']['name']);
        $this->assertSame([], $data['away_team']['players']);
    }

    public function test_a_match_cannot_be_read_through_another_events_url(): void
    {
        $org = $this->org();
        $mine = $this->event($org);
        $other = $this->event($org);
        $match = $this->playedMatch($other);

        // Same organization, published event, valid match id — and still 404,
        // because this match does not belong to the event in the URL.
        $this->getJson("/api/v1/public/events/{$org->slug}/{$mine->slug}/matches/{$match->id}/stats")
            ->assertStatus(404);

        // Contrast: its own event's URL serves it.
        $this->getJson("/api/v1/public/events/{$org->slug}/{$other->slug}/matches/{$match->id}/stats")
            ->assertOk();
    }

    public function test_a_draft_events_match_stats_stay_hidden(): void
    {
        $org = $this->org();
        $event = $this->event($org, 'draft');
        $match = $this->playedMatch($event);

        $this->getJson("/api/v1/public/events/{$org->slug}/{$event->slug}/matches/{$match->id}/stats")
            ->assertStatus(404);
    }
}
