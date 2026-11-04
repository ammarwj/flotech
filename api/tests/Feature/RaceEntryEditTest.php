<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceCategory;
use App\Models\RaceEntry;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\PlatformSettings;
use App\Services\RaceEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Organizer memperbaiki data satu pelari, dan membatalkan pendaftarannya.
 *
 * Dua hal yang diuji di sini **tidak bisa dibuktikan satu assert**, dan
 * keduanya kelas bug yang sudah terjadi di tempat lain:
 *
 * 1. Pintu ini menyentuh **pelarinya, bukan uangnya**. "Nama berubah" tetap
 *    hijau walau endpoint-nya juga menerima `price`, `status`, atau
 *    `race_category_id` — jadi yang di-assert adalah seluruh kolom uang
 *    di-snapshot sebelum/sesudah dan harus **sama**.
 * 2. Tidak ada hard delete. "Kuota kembali" tetap hijau walau barisnya dihapus;
 *    yang memisahkannya adalah barisnya **masih ada** beserta kredit dompetnya.
 */
class RaceEntryEditTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSettings::flush();
        Mail::fake();
        // Di dalam Early Bird. Dipaku karena harga yang di-snapshot baris ini
        // bergantung pada jam, dan separuh yang di-assert adalah snapshot itu.
        Carbon::setTestNow('2026-01-05 03:00:00'); // 10:00 WIB
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- fixtures ----

    /**
     * A fun run on the MANUAL rail, and that is load-bearing for this suite.
     *
     * On the gateway rail with no Midtrans server key — which is the test env —
     * `register()` settles on the spot through the `mock` branch, so every
     * entry would arrive `paid` and the cancel guard would refuse all of them.
     * Manual keeps them `pending`, which is the state an organizer actually
     * cancels. The paid case below is reached by calling markPaid() explicitly,
     * where it is the subject rather than an accident of the fixture.
     */
    private function raceEvent(Organization $org, array $overrides = []): Event
    {
        $org->bankAccounts()->firstOrCreate(
            ['account_number' => '1234567890'],
            [
                'bank_name' => 'BCA',
                'bank_code' => '014',
                'account_holder' => 'Flo Event EO',
                'is_primary' => true,
            ],
        );

        $event = $this->eventOn($org, null, [
            'sport_type' => 'fun_run',
            'payment_method' => 'manual',
            'status' => 'open',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-01',
            'registration_open' => '2026-01-01',
            'registration_close' => '2026-02-28',
            ...$overrides,
        ]);

        $event->racePeriods()->create([
            'name' => 'Early Bird',
            'starts_at' => '2026-01-01T00:00:00+07:00',
            'ends_at' => '2026-01-10T23:59:59+07:00',
            'sort_order' => 0,
        ]);

        return $event->fresh();
    }

    private function category(Event $event, string $name, float $price, ?int $quota = null): RaceCategory
    {
        $category = $event->raceCategories()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'quota' => $quota,
        ]);

        $category->prices()->create([
            'race_period_id' => $event->racePeriods()->firstOrFail()->id,
            'price' => $price,
        ]);

        return $category;
    }

    /** Daftarkan satu pelari lewat alur publik yang sebenarnya. */
    private function enter(Organization $org, Event $event, RaceCategory $category, array $overrides = []): RaceEntry
    {
        $id = $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", [
                'race_category_id' => $category->id,
                'full_name' => 'Ammar',
                'email' => 'ammar@test.com',
                'phone' => '08123456789',
                // No `payment_channel`: the manual rail never asks for one, and
                // the server refuses a channel it did not ask for.
                ...$overrides,
            ])
            ->assertCreated()
            ->json('data.entry.id');

        return RaceEntry::findOrFail($id);
    }

    private function url(Organization $org, Event $event, RaceEntry $entry): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$event->id}/race-entries/{$entry->id}";
    }

    /**
     * Setiap kolom yang pintu ini **tidak** boleh sentuh.
     *
     * Di-snapshot sebagai satu array dan dibandingkan utuh, bukan di-assert
     * satu per satu: kolom yang ditambahkan ke `$fillable` besok ikut terjaring
     * tanpa test ini disentuh — dan `$fillable`-lah yang membuat
     * `$entry->update($data)` berbahaya kalau requestnya melonggar.
     *
     * @return array<string, mixed>
     */
    private function moneyColumns(RaceEntry $entry): array
    {
        return $entry->fresh()->only([
            'race_category_id', 'race_period_id', 'period_name', 'price',
            'platform_fee', 'gateway_fee', 'gateway_tax', 'service_fee',
            'discount_code', 'discount_type', 'discount_value', 'discount_amount',
            'status', 'paid_at', 'invoice_number', 'receipt_number',
            'midtrans_order_id', 'payment_method', 'payment_channel', 'payment_type',
            'qr_code', 'checked_in_at', 'checked_in_by', 'user_id',
        ]);
    }

    // ---- edit ----

    /**
     * Data peserta berubah; satu pun kolom uang tidak.
     *
     * Pasangannya yang mengikatnya: assert "nama jadi Budi" lolos penuh walau
     * request-nya juga menerima `price` dan `status`, dan itulah cara pintu
     * organizer biasanya bocor jadi penulis kedua atas uang.
     */
    public function test_editing_a_runner_touches_no_money_column(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $entry = $this->enter($org, $event, $this->category($event, 'Umum', 75_000));

        $before = $this->moneyColumns($entry);

        $this->actingAs($owner, 'api')
            ->patchJson($this->url($org, $event, $entry), [
                'full_name' => 'Budi Santoso',
                'email' => 'budi@test.com',
                'phone' => '08998877665',
                'gender' => 'L',
                'birth_date' => '1995-04-17',
                'emergency_contact_name' => 'Siti',
                'emergency_contact_phone' => '08111222333',
                'emergency_contact_relation' => 'Ibu',
                'bib_number' => 'A-014',
                // Dikirim dan harus **diabaikan**, bukan ditolak: `$fillable`
                // memuat ketiganya, jadi request yang melonggar akan menulisnya
                // tanpa error sama sekali.
                'price' => 1,
                'status' => 'paid',
                'race_category_id' => $this->category($event, '10K', 150_000)->id,
            ])
            ->assertOk();

        $fresh = $entry->fresh();

        $this->assertSame('Budi Santoso', $fresh->full_name);
        $this->assertSame('budi@test.com', $fresh->email);
        $this->assertSame('08998877665', $fresh->phone);
        $this->assertSame('L', $fresh->gender);
        $this->assertSame('1995-04-17', $fresh->birth_date->toDateString());
        $this->assertSame('Siti', $fresh->emergency_contact_name);
        $this->assertSame('Ibu', $fresh->emergency_contact_relation);
        $this->assertSame('A-014', $fresh->bib_number);

        // Yang mengikatnya. Tanpa ini seluruh assert di atas tetap hijau walau
        // harga, status, dan kategorinya ikut tertulis.
        $this->assertSame($before, $this->moneyColumns($entry));
    }

    /**
     * Kunci yang **absen** meninggalkan nilai tersimpan; `null` mengosongkannya.
     *
     * Dibandingkan dalam satu test karena keduanya beda satu kata di aturannya
     * (`sometimes` vs `nullable`), dan "absen = jangan sentuh" adalah bacaan
     * yang sudah pernah salah di sini (lihat `officials` di update pendaftaran
     * tim): klien yang lebih tua mengirim separuh payload dan menghapus field
     * yang tidak ia tahu ada, dengan toast hijau.
     */
    public function test_an_absent_key_is_left_alone_and_null_clears_it(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $entry = $this->enter($org, $event, $this->category($event, 'Umum', 75_000), [
            'emergency_contact_name' => 'Siti',
            'phone' => '08123456789',
        ]);

        // Hanya nama. Dua field di bawahnya tidak disebut sama sekali.
        $this->actingAs($owner, 'api')
            ->patchJson($this->url($org, $event, $entry), ['full_name' => 'Budi'])
            ->assertOk();

        $this->assertSame('Budi', $entry->fresh()->full_name);
        $this->assertSame('08123456789', $entry->fresh()->phone);
        $this->assertSame('Siti', $entry->fresh()->emergency_contact_name);

        // Dan null benar-benar mengosongkan — kalau tidak, nomor yang salah
        // tidak bisa dihapus, cuma ditimpa.
        $this->actingAs($owner, 'api')
            ->patchJson($this->url($org, $event, $entry), ['phone' => null])
            ->assertOk();

        $this->assertNull($entry->fresh()->phone);
        $this->assertSame('Siti', $entry->fresh()->emergency_contact_name);
    }

    /**
     * Jawaban form kustom lewat gerbang yang sama dengan form publik.
     *
     * Tiga pengiriman dalam satu test, karena masing-masing sendirian lolos
     * walau separuh mekanismenya mati: key yang tidak ada di skema **dibuang**
     * (tanpa itu kolomnya memuat jawaban yang tak pernah dirender), field wajib
     * yang dikosongkan **ditolak 422** (tanpa itu pintu organizer menerima apa
     * yang form publik tolak), dan kunci yang absen meninggalkan jawaban lama.
     */
    public function test_custom_field_answers_go_through_the_same_gate_as_the_public_form(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $event->update(['registration_form' => [
            'team_fields' => [
                ['key' => 'komunitas', 'label' => 'Komunitas', 'type' => 'short_text', 'required' => true],
            ],
        ]]);

        $entry = $this->enter($org, $event->fresh(), $this->category($event, 'Umum', 75_000), [
            'custom_fields' => ['komunitas' => 'Jogja Runners'],
        ]);

        $url = $this->url($org, $event, $entry);

        // (a) Key asing dibuang, yang dikenal tersimpan.
        $this->actingAs($owner, 'api')
            ->patchJson($url, ['custom_fields' => [
                'komunitas' => 'Solo Runners',
                'bukan_field' => 'apa pun',
            ]])
            ->assertOk();

        $this->assertSame(['komunitas' => 'Solo Runners'], $entry->fresh()->custom_fields);

        // (b) Field wajib yang dikosongkan ditolak, dan jawaban lama selamat.
        // The key is read out of `errors` rather than through assertJsonPath:
        // the path itself contains a dot (`custom_fields.komunitas` is ONE key,
        // the same shape the public form answers with), which that helper reads
        // as nesting.
        $errors = $this->actingAs($owner, 'api')
            ->patchJson($url, ['custom_fields' => ['komunitas' => '']])
            ->assertStatus(422)
            ->json('errors');

        $this->assertSame('Komunitas wajib diisi.', $errors['custom_fields.komunitas']);

        $this->assertSame(['komunitas' => 'Solo Runners'], $entry->fresh()->custom_fields);

        // (c) Kunci absen = jangan sentuh. Tanpa ini, memperbaiki nama akan
        // menghapus jawaban form dan 422 di (b) tidak pernah terpicu.
        $this->actingAs($owner, 'api')
            ->patchJson($url, ['full_name' => 'Budi'])
            ->assertOk();

        $this->assertSame(['komunitas' => 'Solo Runners'], $entry->fresh()->custom_fields);
    }

    /**
     * Dokumen yang sudah diunggah pelari tidak terbaca "hilang".
     *
     * Pintu ini tidak menerima `documents` sama sekali, jadi kelengkapan
     * dokumen wajib harus dibaca dari **baris tersimpan**. Tanpa itu, setiap
     * edit pada event yang meminta dokumen ditolak 422 atas berkas yang jelas
     * ada — dan organizer terkunci dari satu-satunya katup yang ia punya.
     */
    public function test_a_stored_document_still_counts_as_uploaded(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $event->update(['registration_form' => [
            'team_documents' => [
                ['key' => 'ktp', 'label' => 'KTP', 'required' => true],
            ],
        ]]);

        $entry = $this->enter($org, $event->fresh(), $this->category($event, 'Umum', 75_000), [
            'documents' => [
                ['document_type' => 'ktp', 'file_url' => 'https://cdn.test/ktp.jpg'],
            ],
        ]);

        $this->assertDatabaseCount('race_entry_documents', 1);

        $this->actingAs($owner, 'api')
            ->patchJson($this->url($org, $event, $entry), [
                'full_name' => 'Budi',
                'custom_fields' => [],
            ])
            ->assertOk();

        $this->assertSame('Budi', $entry->fresh()->full_name);
        // Dan pintu ini tidak menyentuhnya: dokumennya tetap satu.
        $this->assertDatabaseCount('race_entry_documents', 1);
    }

    /**
     * Jendela pendaftaran tertutup tidak mengunci organizer.
     *
     * Itu jam **peserta**, dan asimetri itulah yang membuat pembekuan di
     * `MyTeamController` aman dipasang ketat — organizer adalah katupnya.
     * Dibandingkan dengan event yang jendelanya masih buka: assert satu event
     * lolos walau gate-nya dipasang dan dicabut sekaligus.
     */
    public function test_a_closed_window_does_not_lock_the_organizer_out(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        $closed = $this->raceEvent($org, [
            'registration_open' => '2025-11-01',
            'registration_close' => '2025-12-01',
            'status' => 'registration_closed',
        ]);
        // Didaftarkan saat masih buka, lalu jendelanya ditutup.
        $open = $this->raceEvent($org);

        $openEntry = $this->enter($org, $open, $this->category($open, 'Umum', 75_000));
        $closedEntry = RaceEntry::create([
            ...$openEntry->only([
                'race_period_id', 'period_name', 'price', 'status',
                'payment_method', 'full_name', 'email',
            ]),
            'event_id' => $closed->id,
            'race_category_id' => $this->category($closed, 'Umum', 75_000)->id,
            'midtrans_order_id' => 'RUN-'.Str::upper(Str::random(10)),
        ]);

        $this->actingAs($owner, 'api')
            ->patchJson($this->url($org, $open, $openEntry), ['full_name' => 'Masih Buka'])
            ->assertOk();

        $this->actingAs($owner, 'api')
            ->patchJson($this->url($org, $closed, $closedEntry), ['full_name' => 'Sudah Tutup'])
            ->assertOk();

        $this->assertSame('Masih Buka', $openEntry->fresh()->full_name);
        $this->assertSame('Sudah Tutup', $closedEntry->fresh()->full_name);
    }

    /**
     * Baris event lain tidak bisa dijangkau lewat event ini.
     *
     * 404 dan bukan 403: id-nya tidak ada **di event ini**, dan menjawab 403
     * mengonfirmasi bahwa barisnya ada di tempat lain.
     */
    public function test_an_entry_from_another_event_is_not_reachable(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $mine = $this->raceEvent($org);
        $other = $this->raceEvent($org);

        $entry = $this->enter($org, $other, $this->category($other, 'Umum', 75_000));

        $this->actingAs($owner, 'api')
            ->patchJson($this->url($org, $mine, $entry), ['full_name' => 'Budi'])
            ->assertNotFound();

        $this->assertSame('Ammar', $entry->fresh()->full_name);
    }

    // ---- cancel ----

    /**
     * Membatalkan mengembalikan kuota dan **menyimpan** barisnya.
     *
     * Pasangan `taken` + baris yang masih ada: assert "kuota kembali" sendirian
     * lolos penuh walau barisnya dihapus — dan hard delete-lah yang menggantung
     * kredit dompet, nomor kwitansi, dan QR yang sudah ada di ponsel orang.
     */
    public function test_cancelling_frees_the_place_and_keeps_the_row(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', 75_000, quota: 10);

        $entry = $this->enter($org, $event, $category);

        $this->assertSame(1, $category->fresh()->taken);

        $this->actingAs($owner, 'api')
            ->postJson($this->url($org, $event, $entry).'/cancel')
            ->assertOk();

        $this->assertSame(0, $category->fresh()->taken);
        // Yang mengikatnya: barisnya masih ada, dengan statusnya yang baru.
        $this->assertDatabaseHas('race_entries', [
            'id' => $entry->id,
            'status' => 'cancelled',
        ]);
    }

    /**
     * Membatalkan dua kali tidak mengembalikan dua tempat.
     *
     * Dibandingkan dengan pembatalan pertama yang berhasil: assert "yang kedua
     * 422" sendirian lolos walau yang pertama juga ditolak, dan `taken` negatif
     * adalah kuota yang menjual tempat yang tidak ada.
     */
    public function test_cancelling_twice_does_not_free_two_places(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', 75_000, quota: 10);

        $entry = $this->enter($org, $event, $category);
        $url = $this->url($org, $event, $entry).'/cancel';

        $this->actingAs($owner, 'api')->postJson($url)->assertOk();
        $this->actingAs($owner, 'api')->postJson($url)->assertStatus(422);

        $this->assertSame(0, $category->fresh()->taken);
    }

    /**
     * Pendaftaran lunas ditolak, dan kredit dompetnya tetap utuh.
     *
     * Dibandingkan dengan yang belum lunas di kategori yang sama: assert "yang
     * lunas 422" sendirian lolos walau semua pembatalan ditolak. Kredit dompet
     * ikut di-assert karena itu **alasannya** — membatalkan yang lunas akan
     * menyisakan saldo yang mengklaim uang untuk tempat yang sudah dilepas.
     */
    public function test_a_paid_entry_is_refused_and_keeps_its_wallet_credit(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        // Gateway, unlike every other fixture here: a manual transfer never
        // touches the wallet (the money went straight to the organizer's own
        // account), so on that rail there would be no credit to assert survives
        // — and this test's whole point is that there is one.
        $event = $this->raceEvent($org, ['payment_method' => 'gateway']);
        $category = $this->category($event, 'Umum', 75_000, quota: 10);

        $paid = $this->enter($org, $event, $category, ['payment_channel' => 'va']);
        // Already settled through the `mock` branch — no server key in the test
        // env. Called anyway, and it is a no-op by design: the guard that makes
        // a re-delivered webhook harmless is the same one that makes this safe.
        app(RaceEntryService::class)->markPaid($paid);

        $pending = $this->enter($org, $event, $category, ['payment_channel' => 'va']);
        // The pending half cannot come from the gateway rail for the reason
        // raceEvent() spells out, so it is put back by hand — the subject here
        // is the cancel guard, not how it got there.
        $pending->update(['status' => 'pending', 'paid_at' => null]);

        $this->assertSame(2, $category->fresh()->taken);
        $credits = WalletTransaction::where('source_type', 'race_entry')
            ->where('source_id', $paid->id)
            ->count();
        $this->assertSame(1, $credits);

        $this->actingAs($owner, 'api')
            ->postJson($this->url($org, $event, $paid).'/cancel')
            ->assertStatus(422);

        // Yang belum lunas tetap bisa — tanpa pasangan ini, endpoint yang
        // menolak segalanya lolos.
        $this->actingAs($owner, 'api')
            ->postJson($this->url($org, $event, $pending).'/cancel')
            ->assertOk();

        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertSame('cancelled', $pending->fresh()->status);
        // Satu tempat dilepas, bukan dua — dan kreditnya masih menunjuk ke
        // baris yang masih ada.
        $this->assertSame(1, $category->fresh()->taken);
        $this->assertSame(1, WalletTransaction::where('source_type', 'race_entry')
            ->where('source_id', $paid->id)
            ->count());
    }

    /**
     * Tidak ada hard delete, dan ketiadaannya **adalah** fiturnya.
     *
     * Rute DELETE yang sengaja tidak didaftarkan — kalau suatu hari seseorang
     * menambahkannya, test ini yang jatuh, dan docblock controllernya yang
     * menjelaskan kenapa.
     */
    public function test_there_is_no_delete_endpoint(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $entry = $this->enter($org, $event, $this->category($event, 'Umum', 75_000));

        $this->actingAs($owner, 'api')
            ->deleteJson($this->url($org, $event, $entry))
            ->assertStatus(405);

        $this->assertDatabaseHas('race_entries', ['id' => $entry->id]);
    }

    // ---- aktor ----

    /**
     * Tiga aktor, satu payload: owner / `operator` / anggota org lain.
     *
     * Kedua pintu tulis ini di `org.admin` seperti daftarnya, dan assert "owner
     * sukses" lolos **identik** di bawah `tenant` saja — yang akan meloloskan
     * petugas scan untuk menulis ulang identitas peserta dan melepas kuota.
     */
    public function test_both_writes_are_owner_admin_only(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $outsider = User::factory()->create();

        $org = $this->orgFor($owner);
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', 75_000, quota: 10);
        $entry = $this->enter($org, $event, $category);

        $url = $this->url($org, $event, $entry);

        $this->actingAs($operator, 'api')->patchJson($url, ['full_name' => 'X'])->assertForbidden();
        $this->actingAs($outsider, 'api')->patchJson($url, ['full_name' => 'X'])->assertForbidden();
        $this->actingAs($operator, 'api')->postJson($url.'/cancel')->assertForbidden();
        $this->actingAs($outsider, 'api')->postJson($url.'/cancel')->assertForbidden();

        // Tidak satu pun dari keempatnya menyentuh barisnya.
        $this->assertSame('Ammar', $entry->fresh()->full_name);
        $this->assertSame(1, $category->fresh()->taken);

        $this->actingAs($owner, 'api')->patchJson($url, ['full_name' => 'Budi'])->assertOk();
        $this->actingAs($owner, 'api')->postJson($url.'/cancel')->assertOk();
    }
}
