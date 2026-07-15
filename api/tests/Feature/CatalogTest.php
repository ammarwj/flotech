<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalog — sports, formats, tiebreakers, sponsor tiers — is data now, and
 * everything downstream (validation, dropdowns) reads it.
 */
class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    public function test_public_catalog_lists_sports_and_options(): void
    {
        $data = $this->getJson('/api/v1/catalog')
            ->assertOk()
            ->json('data');

        $this->assertCount(6, $data['sports']);
        $this->assertSame('football', $data['sports'][0]['slug']);
        $this->assertSame('goal', $data['sports'][0]['stats'][0]['role']);
        $this->assertSame('set', collect($data['sports'])->firstWhere('slug', 'volleyball')['scoring']);

        $this->assertCount(4, $data['tournament_formats']);
        $this->assertCount(5, $data['tiebreakers']);
        $this->assertCount(4, $data['sponsor_tiers']);
        $this->assertSame('hybrid', collect($data['tournament_formats'])->firstWhere('key', 'hybrid')['meta']['engine']);
    }

    public function test_a_sport_added_to_the_catalog_can_immediately_host_an_event(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        // Basketball didn't exist in any constant — it's created here, at runtime.
        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/sports', [
                'slug' => 'basketball',
                'name' => 'Basket',
                'color' => '#F97316',
                'scoring' => 'goal',
                'default_match_minutes' => 40,
            ])
            ->assertCreated();

        $sport = Sport::where('slug', 'basketball')->firstOrFail();

        $this->actingAs($admin, 'api')
            ->putJson("/api/v1/admin/sports/{$sport->id}/stats", [
                'stats' => [
                    ['stat_key' => 'points', 'label' => 'Poin', 'short' => 'PTS'],
                    ['stat_key' => 'assists', 'label' => 'Assist', 'short' => 'AST', 'role' => 'assist'],
                    ['stat_key' => 'fouls', 'label' => 'Foul', 'short' => 'F', 'fair_play_weight' => 1],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.stats.0.stat_key', 'points');

        // An organizer can now run a basketball event without a deploy.
        $owner = User::factory()->create();
        $org = $this->org($owner);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", [
                'name' => 'Jogja Basket Open',
                'sport_type' => 'basketball',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-10',
                'categories' => [
                    ['name' => 'Umum', 'tournament_format' => 'league'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.sport.name', 'Basket')
            ->assertJsonPath('data.sport.default_match_minutes', 40);
    }

    public function test_a_format_preset_reuses_an_existing_engine(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        // "Liga 2 Putaran" is a preset: the league engine, home & away by default.
        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/config-options', [
                'group' => 'tournament_format',
                'key' => 'league_double',
                'label' => 'Liga 2 Putaran',
                'meta' => ['engine' => 'league', 'defaults' => ['home_away' => true, 'legs' => 2]],
            ])
            ->assertCreated();

        $owner = User::factory()->create();
        $org = $this->org($owner);

        $event = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", [
                'name' => 'Liga Jogja',
                'sport_type' => 'futsal',
                'start_date' => '2026-09-01',
                'end_date' => '2026-10-30',
                'categories' => [
                    ['name' => 'Umum', 'tournament_format' => 'league_double'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.categories.0.engine', 'league')       // runs on the league engine
            ->assertJsonPath('data.categories.0.bracket_config.legs', 2) // preset defaults applied
            ->json('data');

        // Four teams, double round robin → 12 fixtures, purely from the preset.
        $eventModel = Event::findOrFail($event['id']);
        $category = $eventModel->categories()->firstOrFail();
        foreach (range(1, 4) as $i) {
            $eventModel->teams()->create(['category_id' => $category->id, 'name' => "Team {$i}", 'status' => 'approved']);
        }

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$eventModel->id}/categories/{$category->id}/schedule")
            ->assertCreated()
            ->assertJsonCount(12, 'data');
    }

    public function test_a_format_without_a_real_engine_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/config-options', [
                'group' => 'tournament_format',
                'key' => 'swiss',
                'label' => 'Swiss System',
                'meta' => ['engine' => 'swiss'], // nothing implements this
            ])
            ->assertStatus(422);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/config-options', [
                'group' => 'tournament_format',
                'key' => 'swiss',
                'label' => 'Swiss System',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['meta.engine']]);
    }

    public function test_sport_slug_is_locked_once_events_use_it(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $owner = User::factory()->create();
        $org = $this->org($owner);

        $org->events()->create([
            'name' => 'Cup', 'slug' => 'cup', 'sport_type' => 'futsal',
            'tournament_format' => 'league', 'start_date' => '2026-08-01', 'end_date' => '2026-08-05',
        ]);

        $futsal = Sport::where('slug', 'futsal')->firstOrFail();

        $this->actingAs($admin, 'api')
            ->putJson("/api/v1/admin/sports/{$futsal->id}", ['slug' => 'futsal5', 'name' => 'Futsal'])
            ->assertStatus(422);

        // Renaming and retiring are still fine.
        $this->actingAs($admin, 'api')
            ->putJson("/api/v1/admin/sports/{$futsal->id}", ['name' => 'Futsal Indoor', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Futsal Indoor');
    }

    public function test_a_roster_only_accepts_positions_the_sport_defines(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);

        $event = $org->events()->create([
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-05',
        ]);
        $category = $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league', 'registration_fee' => 0, 'sort_order' => 0,
        ]);

        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/registrations";
        $team = [
            'category_id' => $category->id,
            'name' => 'Garuda FC',
            'contact_name' => 'Budi',
            'contact_phone' => '08123456789',
        ];

        // 'pivot' is one of futsal's positions (SportSeeder); 'sweeper' is not.
        $this->actingAs($owner, 'api')
            ->postJson($url, [...$team, 'players' => [['full_name' => 'Andi', 'position' => 'pivot']]])
            ->assertCreated();

        $this->actingAs($owner, 'api')
            ->postJson($url, [...$team, 'name' => 'Rajawali FC', 'players' => [
                ['full_name' => 'Budi', 'position' => 'sweeper'],
            ]])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['players.0.position']]);
    }

    public function test_renaming_a_position_reaches_every_roster_and_deleting_a_used_one_does_not(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $owner = User::factory()->create();
        $org = $this->org($owner);

        $event = $org->events()->create([
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-05',
        ]);
        $category = $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league', 'registration_fee' => 0, 'sort_order' => 0,
        ]);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations", [
                'category_id' => $category->id,
                'name' => 'Garuda FC',
                'contact_name' => 'Budi',
                'contact_phone' => '08123456789',
                'players' => [['full_name' => 'Andi', 'position' => 'pivot']],
            ])
            ->assertCreated();

        $futsal = Sport::where('slug', 'futsal')->firstOrFail();
        $keep = [
            ['position_key' => 'goalkeeper', 'label' => 'Kiper'],
            ['position_key' => 'anchor', 'label' => 'Anchor'],
            ['position_key' => 'flank', 'label' => 'Flank'],
        ];

        // Dropping 'pivot' would leave Andi holding a position nobody can name.
        $this->actingAs($admin, 'api')
            ->putJson("/api/v1/admin/sports/{$futsal->id}/positions", ['positions' => $keep])
            ->assertStatus(422);

        // Renaming it is the whole point — the roster stores the key, not the word.
        $this->actingAs($admin, 'api')
            ->putJson("/api/v1/admin/sports/{$futsal->id}/positions", [
                'positions' => [...$keep, ['position_key' => 'pivot', 'label' => 'Target Man']],
            ])
            ->assertOk();

        $futsal = collect($this->getJson('/api/v1/catalog')->json('data.sports'))
            ->firstWhere('slug', 'futsal');

        $this->assertSame(
            'Target Man',
            collect($futsal['positions'])->firstWhere('key', 'pivot')['label'],
        );
    }

    public function test_only_super_admin_can_manage_the_catalog(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->getJson('/api/v1/admin/sports')->assertForbidden();
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/admin/config-options', ['group' => 'sponsor_tier', 'key' => 'x', 'label' => 'X'])
            ->assertForbidden();
    }
}
