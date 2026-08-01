<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Jenis akun di /admin/users diturunkan dari jejak user, bukan dari
 * `default_mode` (yang defaultnya 'organizer' untuk setiap akun baru dan cuma
 * mencatat topi terakhir yang dipakai di switcher dashboard).
 *
 * Semua tes di sini **membandingkan** beberapa akun dalam satu daftar: assert
 * "organizer terdeteksi" saja akan tetap lolos walau setiap akun distempel
 * organizer, yang persis kesalahan yang dihindari fitur ini.
 */
class AdminUserAccountTypeTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function openEvent(): Event
    {
        $owner = User::factory()->create();
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'price' => 0]);
        $plan->features()->create(['feature_key' => 'max_teams_per_category', 'value' => '10']);
        $org = Organization::create([
            'name' => 'EO', 'slug' => 'eo-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);

        $event = $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Cup', 'slug' => 'cup', 'sport_type' => 'football',
            'status' => 'open', 'start_date' => '2026-08-01', 'end_date' => '2026-08-10',
            'registration_open' => Carbon::now()->subDay(), 'registration_close' => Carbon::now()->addDays(10),
        ]);

        $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league',
            'registration_fee' => 0, 'sort_order' => 0,
        ]);

        return $event->load('categories', 'organization');
    }

    /** Daftarkan satu tim atas nama $user lewat alur publik yang sebenarnya. */
    private function register(Event $event, User $user, string $name = 'Garuda FC'): void
    {
        $org = $event->organization;

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", [
                'category_id' => $event->categories->first()->id,
                'name' => $name,
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
                'players' => [
                    ['full_name' => 'Player One', 'jersey_number' => '10'],
                    ['full_name' => 'Player Two', 'jersey_number' => '7'],
                ],
            ])
            ->assertCreated();
    }

    /** @return array<string, list<string>> email => account_types */
    private function typesByEmail(array $filters = []): array
    {
        $items = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/users?'.http_build_query($filters + ['per_page' => 100]))
            ->assertOk()
            ->json('data.items');

        return collect($items)->mapWithKeys(fn ($u) => [$u['email'] => $u['account_types']])->all();
    }

    public function test_account_type_separates_organizer_from_participant(): void
    {
        $event = $this->openEvent();
        // Pemilik org dari openEvent() adalah organizer murni.
        $organizer = $event->organization->owner;

        $participant = User::factory()->create();
        $this->register($event, $participant);

        // Akun baru: belum punya organisasi, belum daftar tim. `default_mode`-nya
        // tetap 'organizer' (default kolom) — kalau itu yang dibaca, akun ini
        // akan ikut berlabel organizer.
        $idle = User::factory()->create();
        $this->assertSame('organizer', $idle->fresh()->default_mode);

        $types = $this->typesByEmail();

        $this->assertSame(['organizer'], $types[$organizer->email]);
        $this->assertSame(['participant'], $types[$participant->email]);
        $this->assertSame([], $types[$idle->email]);
    }

    public function test_organizer_who_also_registers_a_team_is_both(): void
    {
        $event = $this->openEvent();
        $organizer = $event->organization->owner;

        // Organizer yang ikut turnamen orang lain — event kedua, org lain.
        $other = $this->openEvent();
        $this->register($other, $organizer);

        $types = $this->typesByEmail();

        $this->assertSame(['organizer', 'participant'], $types[$organizer->email]);
        // Pemilik event kedua tetap organizer saja: label tidak menular.
        $this->assertSame(['organizer'], $types[$other->organization->owner->email]);
    }

    public function test_org_member_without_ownership_counts_as_organizer(): void
    {
        $event = $this->openEvent();
        $operator = User::factory()->create();
        $event->organization->members()->create([
            'user_id' => $operator->id,
            'role' => 'operator',
        ]);

        $participant = User::factory()->create();
        $this->register($event, $participant);

        $types = $this->typesByEmail();

        $this->assertSame(['organizer'], $types[$operator->email]);
        $this->assertSame(['participant'], $types[$participant->email]);
    }

    public function test_type_filter_matches_the_badges_it_filters_on(): void
    {
        $event = $this->openEvent();
        $organizer = $event->organization->owner;

        $participant = User::factory()->create();
        $this->register($event, $participant);

        $idle = User::factory()->create();

        $organizers = $this->typesByEmail(['type' => 'organizer']);
        $this->assertArrayHasKey($organizer->email, $organizers);
        $this->assertArrayNotHasKey($participant->email, $organizers);
        $this->assertArrayNotHasKey($idle->email, $organizers);

        $participants = $this->typesByEmail(['type' => 'participant']);
        $this->assertArrayHasKey($participant->email, $participants);
        $this->assertArrayNotHasKey($organizer->email, $participants);

        $none = $this->typesByEmail(['type' => 'none']);
        $this->assertArrayHasKey($idle->email, $none);
        $this->assertArrayNotHasKey($organizer->email, $none);
        $this->assertArrayNotHasKey($participant->email, $none);
    }

    public function test_list_carries_the_teams_behind_a_participant_badge(): void
    {
        $event = $this->openEvent();
        $participant = User::factory()->create();
        $this->register($event, $participant, 'Garuda FC');

        $items = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/users?per_page=100')
            ->assertOk()
            ->json('data.items');

        $row = collect($items)->firstWhere('email', $participant->email);

        $this->assertSame(['Garuda FC'], array_column($row['managed_teams'], 'name'));
        $this->assertSame(['Cup'], array_column($row['managed_teams'], 'event_name'));
    }
}
