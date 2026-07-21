<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Racket sports are entered three ways: one player (tunggal), a pair (ganda), or
 * a squad (tim). The first two reuse the teams table — the entry *is* its
 * players, so its name is derived from them rather than typed in, which is what
 * keeps every reader (standings, brackets, certificates) unchanged.
 */
class ParticipantTypeTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    private function event(Organization $org, string $participantType, string $sport = 'badminton'): Event
    {
        $event = $org->events()->create([
            'name' => 'Kejurnas',
            'slug' => 'kejurnas-'.uniqid(),
            'sport_type' => $sport,
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $event->categories()->create([
            'name' => 'Utama',
            'slug' => 'utama',
            'participant_type' => $participantType,
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        return $event->load('categories');
    }

    private function registrationsUrl(Organization $org, Event $event): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$event->id}/registrations";
    }

    public function test_doubles_entry_is_named_after_its_pair(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org, 'double');

        $this->actingAs($user, 'api')
            ->postJson($this->registrationsUrl($org, $event), [
                'category_id' => $event->categories->first()->id,
                // What the client sends is a placeholder — the pair is the name.
                'name' => 'Pasangan 1',
                'players' => [
                    ['full_name' => 'Dimas'],
                    ['full_name' => 'Ammar'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Dimas / Ammar');

        $this->assertSame('Dimas / Ammar', $event->teams()->value('name'));
    }

    public function test_singles_entry_is_named_after_its_player(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org, 'single');

        $this->actingAs($user, 'api')
            ->postJson($this->registrationsUrl($org, $event), [
                'category_id' => $event->categories->first()->id,
                'name' => 'Peserta',
                'players' => [['full_name' => 'Dimas']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Dimas');
    }

    public function test_roster_size_is_enforced_for_singles_and_doubles(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org, 'double');
        $categoryId = $event->categories->first()->id;

        foreach ([[], [['full_name' => 'Dimas']], [['full_name' => 'A'], ['full_name' => 'B'], ['full_name' => 'C']]] as $players) {
            $this->actingAs($user, 'api')
                ->postJson($this->registrationsUrl($org, $event), [
                    'category_id' => $categoryId,
                    'name' => 'Pasangan',
                    'players' => $players,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('players');
        }

        // Nothing was half-created along the way.
        $this->assertSame(0, $event->teams()->count());
    }

    public function test_squad_category_keeps_its_typed_name_and_open_roster(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org, 'team');

        $this->actingAs($user, 'api')
            ->postJson($this->registrationsUrl($org, $event), [
                'category_id' => $event->categories->first()->id,
                'name' => 'Spanyol',
                // A squad may claim its slot and fill the list in later.
                'players' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Spanyol');
    }

    public function test_participant_type_must_be_one_the_sport_supports(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", [
                'name' => 'Liga Futsal',
                'sport_type' => 'futsal',
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-30',
                'categories' => [[
                    'name' => 'Ganda',
                    'participant_type' => 'double',
                    'tournament_format' => 'league',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('categories.0.participant_type');
    }

    public function test_participant_type_is_locked_once_entrants_exist(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org, 'double');
        $category = $event->categories->first();

        $event->teams()->create([
            'category_id' => $category->id,
            'name' => 'Dimas / Ammar',
            'status' => 'approved',
        ]);

        // Flipping it would leave a two-player entry sitting in a singles draw,
        // with a name derived under rules that no longer apply.
        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}", [
                'name' => $event->name,
                'sport_type' => 'badminton',
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-30',
                'categories' => [[
                    'id' => $category->id,
                    'name' => 'Utama',
                    'participant_type' => 'single',
                    'tournament_format' => 'league',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('categories.0.participant_type');

        $this->assertSame('double', $category->fresh()->participant_type);
    }
}
