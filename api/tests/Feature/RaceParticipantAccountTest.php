<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceCategory;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Mendaftar fun run membuat akunnya jadi akun **peserta**, dan pendaftarannya
 * muncul di dashboard peserta.
 *
 * `accountTypes()` dulu cuma membaca `managedTeams`, dan fun run tidak menjual
 * tim sama sekali — jadi pelari yang sudah ikut tiga lomba terbaca "belum ada
 * aktivitas": tidak punya `account_types`, lolos `isCrewOnly()` kalau ia juga
 * wasit, dan di /admin/users berbadge "Belum ada aktivitas" tepat di atas tiga
 * pendaftarannya.
 *
 * Tiap uji di sini **membandingkan** pelari murni dengan manajer tim murni (dan
 * dengan akun yang benar-benar kosong), karena nyaris tak ada satu assert pun
 * yang bisa membuktikannya sendiri: "pelari berbadge peserta" tetap hijau walau
 * setiap akun distempel peserta, yang persis kesalahan yang dihindari fitur ini.
 */
class RaceParticipantAccountTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSettings::flush();
        Mail::fake();
        // Di dalam Early Bird. Dipaku karena harga & jendela pendaftaran yang
        // dipakai fixture ini memang bergantung pada jam.
        Carbon::setTestNow('2026-01-05 03:00:00'); // 10:00 WIB
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- fixtures ----

    private function raceEvent(Organization $org): Event
    {
        $event = $this->eventOn($org, null, [
            'sport_type' => 'fun_run',
            'status' => 'open',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-01',
            'registration_open' => '2026-01-01',
            'registration_close' => '2026-02-28',
        ]);

        $event->racePeriods()->create([
            'name' => 'Early Bird',
            'starts_at' => '2026-01-01T00:00:00+07:00',
            'ends_at' => '2026-01-10T23:59:59+07:00',
            'sort_order' => 0,
        ]);

        return $event->fresh();
    }

    private function raceCategory(Event $event, string $name = 'Umum', float $price = 0): RaceCategory
    {
        $category = $event->raceCategories()->create([
            'name' => $name,
            'slug' => Str::slug($name),
        ]);

        $category->prices()->create([
            'race_period_id' => $event->racePeriods()->firstOrFail()->id,
            'price' => $price,
        ]);

        return $category;
    }

    /**
     * Daftarkan satu pelari lewat alur publik yang sebenarnya.
     *
     * Harga 0 supaya tidak ada rail pembayaran yang ikut diuji di sini — yang
     * diuji adalah apa yang baris pendaftarannya buat dari akunnya.
     */
    private function enterRace(User $runner, Event $event, RaceCategory $category, string $name = 'Ammar'): string
    {
        return $this->actingAs($runner, 'api')
            ->postJson("/api/v1/public/events/{$event->organization->slug}/{$event->slug}/race/register", [
                'race_category_id' => $category->id,
                'full_name' => $name,
                'email' => $runner->email,
                'phone' => '08123456789',
            ])
            ->assertCreated()
            ->json('data.entry.id');
    }

    /** Daftarkan satu tim lewat alur publik, untuk pembandingnya. */
    private function enterTeam(User $manager, Event $event): void
    {
        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/public/events/{$event->organization->slug}/{$event->slug}/register", [
                'category_id' => $event->categories->first()->id,
                'name' => 'Garuda FC',
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
                'players' => [
                    ['full_name' => 'Player One', 'jersey_number' => '10'],
                    ['full_name' => 'Player Two', 'jersey_number' => '7'],
                ],
            ])
            ->assertCreated();
    }

    /** Event beregu biasa dengan satu kategori, untuk manajer pembandingnya. */
    private function teamEvent(Organization $org): Event
    {
        $event = $this->eventOn($org, null, [
            'sport_type' => 'football',
            'status' => 'open',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-10',
            'registration_open' => '2026-01-01',
            'registration_close' => '2026-02-28',
        ]);

        $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        return $event->fresh()->load('categories', 'organization');
    }

    // ---- account_types ----

    /**
     * Pelari fun run dan manajer tim sama-sama peserta, akun kosong bukan.
     *
     * Dibandingkan bertiga dalam satu respons: assert pelari sendirian lolos
     * walau `accountTypes()` ditulis `return ['participant']`, dan assert
     * manajer sendirian adalah keadaan **sebelum** perubahan ini — yang hijau
     * penuh sementara pelarinya terbaca kosong.
     */
    public function test_a_fun_run_entry_makes_the_account_a_participant(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        $runner = User::factory()->create();
        $this->enterRace($runner, $race = $this->raceEvent($org), $this->raceCategory($race));

        $manager = User::factory()->create();
        $this->enterTeam($manager, $this->teamEvent($org));

        $idle = User::factory()->create();

        $types = $this->accountTypesByEmail();

        $this->assertSame(['participant'], $types[$runner->email]);
        $this->assertSame(['participant'], $types[$manager->email]);
        $this->assertSame([], $types[$idle->email]);
        // Dan labelnya tidak menular ke penyelenggaranya, yang justru memegang
        // event fun run-nya.
        $this->assertSame(['organizer'], $types[$owner->email]);
    }

    /**
     * Respons auth membawa jawaban yang sama dengan layar admin.
     *
     * Dua pembaca `accountTypes()` dengan dua cara memuat relasi — `load()` di
     * admin, `loadExists()` di sini — dan `hasAccountContext()` adalah gerbang
     * yang membuat relasi yang lupa dimuat terbaca "tidak punya". Pelari yang
     * terbaca kosong di sini adalah pelari yang `isCrewOnly()` salah kenali dan
     * `MODE_HOME` lempar ke dashboard organizer, lalu /onboarding.
     *
     * Dibandingkan dengan akun kosong dalam uji yang sama, karena `account_types`
     * yang **absen** (relasi tak dimuat) dan yang **kosong** (akun baru) sama
     * bentuknya di klien.
     */
    public function test_me_publishes_the_participant_type_for_a_runner(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $race = $this->raceEvent($org);
        $category = $this->raceCategory($race);

        $runner = User::factory()->create();
        $this->enterRace($runner, $race, $category);

        $payload = $this->actingAs($runner, 'api')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->json('data');

        $this->assertSame(['participant'], $payload['account_types']);

        $idle = $this->actingAs(User::factory()->create(), 'api')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->json('data');

        $this->assertSame([], $idle['account_types']);
    }

    /**
     * Filter `type` di /admin/users memakai definisi yang sama dengan badge-nya.
     *
     * Keduanya dibandingkan dalam satu uji karena kalau menyimpang, satu layar
     * membantah dirinya sendiri: daftar `participant` tanpa satu pun pelari
     * sementara kartu pelari itu berbadge peserta. `none` ikut diuji karena ia
     * negasi dari daftar yang sama — tambah jejak di satu sisi tanpa sisi lain
     * membuat satu akun masuk ke **dua** filter yang saling eksklusif.
     */
    public function test_the_participant_filter_matches_the_badge_it_filters_on(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $race = $this->raceEvent($org);

        $runner = User::factory()->create();
        $this->enterRace($runner, $race, $this->raceCategory($race));

        $manager = User::factory()->create();
        $this->enterTeam($manager, $this->teamEvent($org));

        $idle = User::factory()->create();

        $participants = $this->accountTypesByEmail(['type' => 'participant']);
        $this->assertArrayHasKey($runner->email, $participants);
        $this->assertArrayHasKey($manager->email, $participants);
        $this->assertArrayNotHasKey($idle->email, $participants);

        $none = $this->accountTypesByEmail(['type' => 'none']);
        $this->assertArrayHasKey($idle->email, $none);
        $this->assertArrayNotHasKey($runner->email, $none);
        $this->assertArrayNotHasKey($manager->email, $none);
    }

    /**
     * Kartu admin membawa pendaftaran di belakang badge-nya, dan di daftarnya
     * sendiri.
     *
     * `race_entries` di samping `managed_teams`, bukan dilipat ke dalamnya: fun
     * run tidak punya tim, jadi yang dicetak adalah nama **kategori**. Keduanya
     * dibandingkan supaya satu daftar dengan dua arti kolom tidak bisa lolos.
     */
    public function test_the_admin_card_carries_the_entries_behind_the_badge(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $race = $this->raceEvent($org);

        $runner = User::factory()->create();
        $this->enterRace($runner, $race, $this->raceCategory($race, '5K'));

        $manager = User::factory()->create();
        $this->enterTeam($manager, $this->teamEvent($org));

        $rows = $this->adminRows();

        $runnerRow = collect($rows)->firstWhere('email', $runner->email);
        $this->assertSame(['5K'], array_column($runnerRow['race_entries'], 'category_name'));
        $this->assertSame([], $runnerRow['managed_teams']);

        // Dan arah sebaliknya: manajer tim tidak mendapat baris pendaftaran
        // lomba. Tanpa pasangan ini, dua daftar yang tertukar lolos.
        $managerRow = collect($rows)->firstWhere('email', $manager->email);
        $this->assertSame(['Garuda FC'], array_column($managerRow['managed_teams'], 'name'));
        $this->assertSame([], $managerRow['race_entries']);
    }

    /**
     * Mencari nama event fun run menemukan pelarinya.
     *
     * Kelima jalan dari user ke event ada di `orWhereInEvent()`, dan pendaftaran
     * lomba adalah satu-satunya jalan bagi akun yang tidak punya tim sama
     * sekali. Dibandingkan dengan akun yang tidak ikut lomba itu: assert
     * "pelarinya ketemu" sendirian lolos walau pencariannya mengembalikan
     * seluruh tabel.
     */
    public function test_searching_the_event_name_finds_its_runners(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $race = $this->raceEvent($org);
        $race->update(['name' => 'Jogja Color Run']);

        $runner = User::factory()->create();
        $this->enterRace($runner, $race->fresh(), $this->raceCategory($race));

        $stranger = User::factory()->create();

        $found = $this->accountTypesByEmail(['q' => 'Jogja Color Run']);

        $this->assertArrayHasKey($runner->email, $found);
        $this->assertArrayNotHasKey($stranger->email, $found);
    }

    // ---- dashboard peserta ----

    /**
     * `GET my-race-entries` memberi pendaftaran milik pemanggilnya saja.
     *
     * Dibandingkan dengan pelari kedua di lomba yang sama: assert "pendaftaranku
     * ada" lolos penuh walau endpoint-nya mengembalikan seluruh tabel — dan
     * `race_entries` memuat email, nomor HP, dan kontak darurat orang lain.
     */
    public function test_my_race_entries_only_returns_the_callers_own(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $race = $this->raceEvent($org);
        $category = $this->raceCategory($race);

        $mine = User::factory()->create();
        $entryId = $this->enterRace($mine, $race, $category, 'Ammar');

        $theirs = User::factory()->create();
        $otherId = $this->enterRace($theirs, $race, $category, 'Budi');

        $items = $this->actingAs($mine, 'api')
            ->getJson('/api/v1/my-race-entries')
            ->assertOk()
            ->json('data');

        $this->assertSame([$entryId], array_column($items, 'id'));
        $this->assertNotContains($otherId, array_column($items, 'id'));
        // Kategori & event-nya ikut, karena itu yang dicetak kartunya — tanpa
        // relasinya dimuat, `whenLoaded` membuat keduanya absen dan kartunya
        // mencetak "—" untuk pendaftaran yang jelas punya keduanya.
        $this->assertSame('Umum', $items[0]['category']['name']);
        $this->assertSame($race->name, $items[0]['event']['name']);
    }

    /**
     * Nomor rekening organizer tidak ikut di daftar ini.
     *
     * `RaceEntryResource::payTo()` hanya menjawab saat seluruh rantai
     * `event.organization.bankAccounts` di-eager-load, jadi "jangan muat
     * bankAccounts" **adalah** penegakannya — dan itu tidak terlihat dari
     * controllernya. Yang diperiksa **nilainya**, bukan ada/tidaknya key:
     * `when()` di resource bercabang pada status order (manual + belum lunas),
     * yang di sini memang benar, jadi key-nya ada dan null-lah jawabannya.
     * Dibandingkan dengan halaman bukti publik, yang justru wajib membawanya:
     * assert "null di sini" sendirian lolos walau nomornya tidak pernah terbit
     * di mana pun dan panel transfer manual pembeli kosong.
     */
    public function test_the_list_withholds_the_bank_details_the_proof_page_needs(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $org->bankAccounts()->create([
            'bank_name' => 'BCA',
            'bank_code' => '014',
            'account_number' => '1234567890',
            'account_holder' => 'Flo Event EO',
            'is_primary' => true,
        ]);

        PlatformSettings::put(['payment_gateway_enabled' => false], null);
        PlatformSettings::flush();

        $race = $this->raceEvent($org);
        $runner = User::factory()->create();
        $entryId = $this->enterRace($runner, $race, $this->raceCategory($race, 'Umum', 75_000));

        $listed = $this->actingAs($runner, 'api')
            ->getJson('/api/v1/my-race-entries')
            ->assertOk()
            ->json('data.0');

        $this->assertNull($listed['bank_account'] ?? null);

        $proof = $this->getJson("/api/v1/race-entries/{$entryId}")
            ->assertOk()
            ->json('data');

        $this->assertSame('1234567890', $proof['bank_account']['account_number']);
    }

    /** Tamu tidak punya daftar pendaftaran. */
    public function test_my_race_entries_needs_an_account(): void
    {
        $this->getJson('/api/v1/my-race-entries')->assertUnauthorized();
    }

    // ---- helpers ----

    /** @return array<string, list<string>> email => account_types */
    private function accountTypesByEmail(array $filters = []): array
    {
        return collect($this->adminRows($filters))
            ->mapWithKeys(fn ($u) => [$u['email'] => $u['account_types']])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function adminRows(array $filters = []): array
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        return $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/users?'.http_build_query($filters + ['per_page' => 100]))
            ->assertOk()
            ->json('data.items');
    }
}
