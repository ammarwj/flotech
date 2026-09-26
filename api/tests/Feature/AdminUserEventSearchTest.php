<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Team;
use App\Models\User;
use App\Services\EventPersonnelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Mengetik nama event di kotak pencarian /admin/users menemukan **orang-orang**
 * event itu.
 *
 * Ada empat jalan dari seorang user ke sebuah event, dan yang membuat tes ini
 * ada adalah bahwa mengabaikan salah satunya gagal dengan cara yang paling
 * tidak enak: kotaknya menjawab "tidak ada" untuk orang yang jelas-jelas ada di
 * sana, dan si super admin menyimpulkan pencariannya rusak — bukan bahwa satu
 * jalan tidak ikut di-OR.
 *
 * Semuanya **dibandingkan dalam satu daftar**: selalu ada satu event kedua
 * dengan orang-orangnya sendiri, dan assert "yang dicari ketemu" saja akan
 * tetap lolos walau klausanya mengembalikan seluruh tabel — yang persis bentuk
 * kegagalan kalau bungkus `where(...)` di `index()` hilang.
 */
class AdminUserEventSearchTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function eventNamed(string $name): Event
    {
        $org = $this->orgFor(User::factory()->create(), $name.' EO');

        return $this->eventOn($org, null, ['name' => $name]);
    }

    /**
     * Email yang dikembalikan pencarian, diurut supaya perbandingannya tidak
     * bergantung pada urutan `created_at`.
     *
     * @return array<int, string>
     */
    private function search(string $q, array $filters = []): array
    {
        $emails = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/users?'.http_build_query($filters + ['q' => $q, 'per_page' => 100]))
            ->assertOk()
            ->json('data.items.*.email');

        sort($emails);

        return $emails;
    }

    private function teamManagedBy(Event $event, User $manager, string $name = 'Garuda'): Team
    {
        $category = $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum-'.uniqid(), 'tournament_format' => 'league',
            'registration_fee' => 0, 'sort_order' => 0,
        ]);

        return Team::create([
            'event_id' => $event->id,
            'category_id' => $category->id,
            'name' => $name,
            'contact_name' => 'Andi',
            'contact_phone' => '0811',
            'manager_user_id' => $manager->id,
            'status' => 'approved',
            'registered_at' => now(),
        ]);
    }

    public function test_searching_an_event_name_finds_all_four_kinds_of_people_in_it(): void
    {
        Notification::fake();

        // Event yang dicari, beserta satu orang di setiap jalan.
        $event = $this->eventNamed('Piala Kaboax');
        $org = $event->organization;
        $owner = $org->owner;

        $member = User::factory()->create(['email' => 'operator@example.test']);
        $org->members()->create(['user_id' => $member->id, 'role' => 'operator']);

        $manager = User::factory()->create(['email' => 'manajer@example.test']);
        $this->teamManagedBy($event, $manager);

        app(EventPersonnelService::class)->sync($event, [
            ['full_name' => 'Wasit A', 'kind' => 'referee', 'email' => 'wasit@example.test'],
        ]);
        $referee = User::where('email', 'wasit@example.test')->firstOrFail();

        // Event pembanding. Orang-orangnya punya keempat jalan yang sama, jadi
        // satu-satunya yang bisa membedakannya adalah nama event.
        $other = $this->eventNamed('Turnamen Lain');
        $otherOwner = $other->organization->owner;
        $otherManager = User::factory()->create(['email' => 'manajer-lain@example.test']);
        $this->teamManagedBy($other, $otherManager);
        app(EventPersonnelService::class)->sync($other, [
            ['full_name' => 'Wasit B', 'kind' => 'referee', 'email' => 'wasit-lain@example.test'],
        ]);

        $found = $this->search('Kaboax');

        // Keempat jalan, sekaligus — dan tidak ada yang lain. Empat assert
        // terpisah "ada di hasil" akan lolos untuk klausa yang mengembalikan
        // semua user, yang justru gejala yang paling mungkin terjadi di sini.
        $this->assertSame(
            collect([$owner->email, $member->email, $manager->email, $referee->email])->sort()->values()->all(),
            $found,
        );

        $this->assertNotContains($otherOwner->email, $found);
        $this->assertNotContains($otherManager->email, $found);
        $this->assertNotContains('wasit-lain@example.test', $found);
    }

    public function test_event_search_ignores_letter_case(): void
    {
        Notification::fake();

        $event = $this->eventNamed('Piala Kaboax');
        $owner = $event->organization->owner;
        $this->eventNamed('Turnamen Lain');

        // Sama seperti AdminUserSearchTest: LIKE polos case-sensitive di
        // Postgres, dan sqlite di sini tidak akan memerahkannya — yang dijaga
        // adalah bentuk querynya tetap lewat Search::anyColumn.
        foreach (['kaboax', 'KABOAX', 'Kaboax'] as $q) {
            $this->assertContains($owner->email, $this->search($q), "gagal untuk '{$q}'");
        }
    }

    /**
     * Pencarian dan filter harus **ber-AND**, bukan ber-OR.
     *
     * Ini yang dijaga oleh bungkus `where(...)` di sekeliling klausa pencarian
     * di `index()`. Tanpanya `orWhereHas` bocor ke level teratas dan meng-OR
     * dirinya dengan filter di sebelahnya, jadi filter role/jenis akun terbaca
     * mati — dan satu-satunya gejalanya adalah hasil yang "kebanyakan".
     */
    public function test_an_event_search_still_obeys_the_type_filter(): void
    {
        Notification::fake();

        $event = $this->eventNamed('Piala Kaboax');
        $owner = $event->organization->owner;

        $manager = User::factory()->create(['email' => 'manajer@example.test']);
        $this->teamManagedBy($event, $manager);

        app(EventPersonnelService::class)->sync($event, [
            ['full_name' => 'Wasit A', 'kind' => 'referee', 'email' => 'wasit@example.test'],
        ]);

        // Satu event, satu kata kunci, tiga filter: yang tersisa harus berubah.
        $this->assertSame([$owner->email], $this->search('Kaboax', ['type' => 'organizer']));
        $this->assertSame([$manager->email], $this->search('Kaboax', ['type' => 'participant']));
        $this->assertSame(['wasit@example.test'], $this->search('Kaboax', ['type' => 'crew']));
    }

    /**
     * Petugas bukan `account_types`, jadi dua filter yang berbeda tidak boleh
     * mengembalikan baris yang sama.
     *
     * Dibandingkan karena inilah cara `none` gagal secara diam-diam: sebelum
     * `whereDoesntHave('personnelAssignments')` ada, seorang wasit murni lolos
     * filter "Belum ada aktivitas" **dan** berbadge Wasit di kartu yang sama —
     * filter dan badge saling membantah di satu layar.
     */
    public function test_crew_and_idle_filters_never_return_the_same_row(): void
    {
        Notification::fake();

        $event = $this->eventNamed('Piala Kaboax');

        app(EventPersonnelService::class)->sync($event, [
            ['full_name' => 'Wasit A', 'kind' => 'referee', 'email' => 'wasit@example.test'],
            ['full_name' => 'Staf B', 'kind' => 'staff', 'email' => 'staf@example.test'],
        ]);

        $idle = User::factory()->create(['email' => 'nganggur@example.test']);

        $crew = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/users?type=crew&per_page=100')
            ->assertOk()
            ->json('data.items.*.email');

        $none = $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/users?type=none&per_page=100')
            ->assertOk()
            ->json('data.items.*.email');

        sort($crew);

        $this->assertSame(['staf@example.test', 'wasit@example.test'], $crew);
        $this->assertContains($idle->email, $none);
        $this->assertNotContains('wasit@example.test', $none);
        $this->assertNotContains('staf@example.test', $none);
        $this->assertEmpty(array_intersect($crew, $none));
    }

    /**
     * Badge Wasit/Staf di kartu butuh `officiating` ada di daftar, dan itu
     * cuma datang dari `personnelAssignments` yang di-eager-load.
     */
    public function test_the_list_carries_the_assignments_behind_a_crew_badge(): void
    {
        Notification::fake();

        $event = $this->eventNamed('Piala Kaboax');

        app(EventPersonnelService::class)->sync($event, [
            ['full_name' => 'Wasit A', 'kind' => 'referee', 'email' => 'wasit@example.test'],
        ]);

        $row = collect(
            $this->actingAs($this->superAdmin(), 'api')
                ->getJson('/api/v1/admin/users?per_page=100')
                ->assertOk()
                ->json('data.items')
        )->firstWhere('email', 'wasit@example.test');

        $this->assertSame(['Piala Kaboax'], array_column($row['officiating'], 'event_name'));
        $this->assertSame(['referee'], array_column($row['officiating'], 'kind'));
        // Dan bukan lewat account_types: akun petugas murni kosong di sana, dan
        // itu memang benar — yang salah dulu adalah kartunya menyebutnya "belum
        // ada aktivitas" karena tidak punya sumber kedua untuk dibaca.
        $this->assertSame([], $row['account_types']);
    }
}
