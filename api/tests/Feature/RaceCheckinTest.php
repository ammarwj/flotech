<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceCategory;
use App\Models\RaceEntry;
use App\Models\User;
use App\Services\RaceEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Check-in di meja registrasi: QR-nya, kapan ia lahir, dan siapa yang boleh
 * memindainya.
 *
 * Tiap uji **membandingkan** dua keadaan yang harus keluar berbeda, karena
 * hampir tidak ada di sini yang bisa dibuktikan dengan satu assert. "QR-nya
 * ada" tetap hijau walau ia dicetak sebelum uangnya masuk; "check-in berhasil"
 * tetap hijau walau pemindaian kedua ikut diterima; "kodenya terbit untuk entry
 * lama" tetap hijau walau tiap pembacaan mencetak kode baru dan membatalkan
 * yang sudah ditunjukkan.
 */
class RaceCheckinTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        Carbon::setTestNow('2026-01-05 03:00:00'); // 10:00 WIB, inside the window
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
        ]);

        return $event->fresh();
    }

    private function category(Event $event, string $name, float $price): RaceCategory
    {
        $category = $event->raceCategories()->create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
        ]);

        $category->prices()->create([
            'race_period_id' => $event->racePeriods()->firstOrFail()->id,
            'price' => $price,
        ]);

        return $category;
    }

    private function register(Organization $org, Event $event, RaceCategory $category, string $name = 'AMMAR'): RaceEntry
    {
        $id = $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", [
                'race_category_id' => $category->id,
                'full_name' => $name,
                'email' => strtolower($name).'@test.com',
                'payment_channel' => 'va',
            ])
            ->assertCreated()
            ->json('data.entry.id');

        return RaceEntry::findOrFail($id);
    }

    // ---- when the credential is born ----

    /**
     * Invarian: QR lahir saat lunas, tidak pernah sebelumnya.
     *
     * Dibandingkan unpaid vs paid pada event yang sama. Assert "entry lunas
     * punya QR" sendirian akan lolos walau `hold()` yang mencetaknya — dan
     * kode yang terbit sebelum uang masuk adalah kredensial yang
     * memasukkan orang yang belum bayar.
     */
    public function test_the_qr_is_minted_on_settlement_and_never_before(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->bankAccount($org);

        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', 75_000);

        // Manual rail: stays pending, so nothing may be minted.
        \App\Services\PlatformSettings::put(['payment_gateway_enabled' => false], null);
        \App\Services\PlatformSettings::flush();
        $pending = $this->register($org, $event, $category, 'DIMAS');

        // Gateway rail: settles immediately in tests.
        \App\Services\PlatformSettings::put(['payment_gateway_enabled' => true], null);
        \App\Services\PlatformSettings::flush();
        $paid = $this->register($org, $event, $category, 'AMMAR');

        $this->assertSame('pending', $pending->status);
        $this->assertNull($pending->qr_code);

        // The binding half.
        $this->assertSame('paid', $paid->status);
        $this->assertNotNull($paid->qr_code);
        $this->assertStringStartsWith('RUN-', $paid->qr_code);
    }

    /**
     * A free entry gets a QR even though it never gets a receipt.
     *
     * Compared against the receipt, which is the point: `price > 0` is a
     * question about billing, and a sponsored run still has a start line.
     */
    public function test_a_free_entry_has_a_qr_but_no_receipt(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $free = $this->category($event, 'Komunitas', 0);

        $entry = $this->register($org, $event, $free);

        $this->assertSame('paid', $entry->status);
        $this->assertNotNull($entry->qr_code);
        // A Rp 0 receipt claims a transaction that never happened.
        $this->assertNull($entry->receipt_number);
    }

    /**
     * A re-delivered webhook must not mint a second code.
     *
     * Compared before/after a second settlement: asserting "it has a code"
     * would pass even if every delivery replaced it — and the one already on
     * the runner's phone would quietly stop scanning.
     */
    public function test_a_resettlement_keeps_the_same_qr(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', 75_000);

        $entry = $this->register($org, $event, $category);
        $first = $entry->qr_code;

        app(RaceEntryService::class)->markPaid($entry->fresh());

        $this->assertNotNull($first);
        $this->assertSame($first, $entry->fresh()->qr_code);
    }

    /**
     * Invarian: entry lunas yang lahir sebelum fitur ini dapat QR saat dibaca,
     * dan kode itu TIDAK berubah di pembacaan berikutnya.
     *
     * Pasangan kedua itulah yang mengikat: halaman bukti mem-poll tiap empat
     * detik, jadi `update` tanpa syarat akan mencetak ULID baru tiap kali dan
     * QR yang sedang ditampilkan berhenti bisa dipindai tanpa ada yang
     * menjelaskan kenapa. Assert "kodenya terbit" sendirian lolos sepenuhnya.
     */
    public function test_a_legacy_paid_entry_gets_one_stable_qr_on_read(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', 75_000);

        $entry = $this->register($org, $event, $category);
        // Exactly what a phase-1 settlement left behind.
        RaceEntry::whereKey($entry->id)->update(['qr_code' => null]);

        $first = $this->getJson("/api/v1/race-entries/{$entry->id}")
            ->assertOk()
            ->json('data.qr_code');

        $second = $this->getJson("/api/v1/race-entries/{$entry->id}")
            ->assertOk()
            ->json('data.qr_code');

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
    }

    /**
     * Two readers arriving together mint ONE code, and the winner's is the one
     * that stands.
     *
     * The stable-on-reread test above does not prove this and I checked that it
     * does not: `ensureQrCode()` returns early once a code exists, so a second
     * sequential read never reaches the UPDATE at all — dropping the
     * `whereNull` guard leaves that test green. This is the test that fails,
     * because it calls the service twice from the SAME stale instance, which is
     * exactly what the proof page's four-second poll produces when a runner has
     * it open on two devices. Without the guard the second write replaces a
     * code the first reader already rendered, and the QR on screen silently
     * stops scanning.
     */
    public function test_two_concurrent_readers_mint_one_code(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', 75_000);

        $entry = $this->register($org, $event, $category);
        RaceEntry::whereKey($entry->id)->update(['qr_code' => null]);

        $service = app(RaceEntryService::class);

        // Two readers that both loaded the row before either wrote — the same
        // instance stands in for two requests holding the same stale state.
        $stale = RaceEntry::findOrFail($entry->id);
        $first = $service->ensureQrCode($stale);
        $second = $service->ensureQrCode($stale);

        $this->assertNotNull($first->qr_code);
        // The loser must read back the winner's code, not its own.
        $this->assertSame($first->qr_code, $second->qr_code);
        $this->assertSame($first->qr_code, $entry->fresh()->qr_code);
    }

    /**
     * An unpaid entry is never given one by being read, however often.
     *
     * The other half of the backfill: `ensureQrCode()` guards on status, and
     * without that guard the proof page would hand a credential to someone
     * who is still mid-transfer.
     */
    public function test_reading_an_unpaid_entry_mints_nothing(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->bankAccount($org);
        \App\Services\PlatformSettings::put(['payment_gateway_enabled' => false], null);
        \App\Services\PlatformSettings::flush();

        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', 75_000);
        $entry = $this->register($org, $event, $category);

        $payload = $this->getJson("/api/v1/race-entries/{$entry->id}")->assertOk()->json('data');

        // Absent, not null: the page renders "aktif setelah lunas" rather than
        // an empty QR frame.
        $this->assertArrayNotHasKey('qr_code', $payload);
        $this->assertNull($entry->fresh()->qr_code);
    }

    // ---- the desk ----

    /** @return array{0: Organization, 1: Event, 2: RaceEntry, 3: User} */
    private function deskSetup(): array
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', 75_000);
        $entry = $this->register($org, $event, $category);

        return [$org, $event, $entry, $owner];
    }

    /**
     * Invarian: satu kode memasukkan satu orang, sekali.
     *
     * Dibandingkan pemindaian pertama vs kedua atas kode yang sama. Assert
     * "check-in berhasil" sendirian akan lolos walau pemindaian kedua ikut
     * diterima — dan dua orang bisa masuk dengan satu tangkapan layar.
     */
    public function test_a_code_admits_once_and_refuses_the_second_time(): void
    {
        [$org, $event, $entry, $owner] = $this->deskSetup();
        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/race-scan";

        $this->actingAs($owner, 'api')
            ->postJson($url, ['qr_code' => $entry->qr_code])
            ->assertOk()
            ->assertJsonPath('data.result', 'valid')
            ->assertJsonPath('data.entry.full_name', 'AMMAR');

        $this->assertNotNull($entry->fresh()->checked_in_at);
        $this->assertSame($owner->id, $entry->fresh()->checked_in_by);

        $this->actingAs($owner, 'api')
            ->postJson($url, ['qr_code' => $entry->qr_code])
            ->assertStatus(409)
            ->assertJsonPath('errors.result', 'used');
    }

    /**
     * The three refusals are distinguishable, because the desk can act on the
     * difference: an unknown code is somebody else's, an unpaid one is a
     * payment to chase, a used one is a runner already inside.
     */
    public function test_the_refusals_are_told_apart(): void
    {
        [$org, $event, , $owner] = $this->deskSetup();
        $this->bankAccount($org);
        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/race-scan";

        $this->actingAs($owner, 'api')
            ->postJson($url, ['qr_code' => 'RUN-nothinghere'])
            ->assertStatus(404)
            ->assertJsonPath('errors.result', 'invalid');

        // An unpaid entry with a code can only be made by hand — which is
        // precisely what this guard is for, since nothing else writes one.
        $unpaid = RaceEntry::create([
            'event_id' => $event->id,
            'race_category_id' => $event->raceCategories()->firstOrFail()->id,
            'full_name' => 'BELUM BAYAR',
            'email' => 'b@test.com',
            'race_period_id' => $event->racePeriods()->firstOrFail()->id,
            'period_name' => 'Early Bird',
            'price' => 75_000,
            'status' => 'pending',
            'qr_code' => RaceEntryService::newQrCode(),
        ]);

        $this->actingAs($owner, 'api')
            ->postJson($url, ['qr_code' => $unpaid->qr_code])
            ->assertStatus(409)
            ->assertJsonPath('errors.result', 'unpaid');

        $this->assertNull($unpaid->fresh()->checked_in_at);
    }

    /**
     * A code is scoped to its own event.
     *
     * Compared as the same code against two events: asserting the 404 alone
     * would pass even if the lookup were broken for everyone.
     */
    public function test_a_code_from_another_race_is_refused_here(): void
    {
        [$org, $event, $entry, $owner] = $this->deskSetup();

        $other = $this->raceEvent($org);
        $this->category($other, 'Umum', 50_000);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$other->id}/race-scan", ['qr_code' => $entry->qr_code])
            ->assertStatus(404)
            ->assertJsonPath('errors.result', 'invalid');

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-scan", ['qr_code' => $entry->qr_code])
            ->assertOk();
    }

    /**
     * The desk door is `tenant`, not `org.admin`: the operator running
     * registration is exactly who scans. Three actors in one test — asserting
     * the owner's 200 alone passes identically under either gate.
     */
    public function test_the_desk_is_open_to_operators_but_not_outsiders(): void
    {
        [$org, $event, $entry, $owner] = $this->deskSetup();

        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);
        $outsider = User::factory()->create();

        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/race-scan";

        $this->actingAs($operator, 'api')
            ->postJson($url, ['qr_code' => $entry->qr_code])
            ->assertOk();

        $this->actingAs($outsider, 'api')
            ->postJson($url, ['qr_code' => $entry->qr_code])
            ->assertForbidden();

        // And the owner still reaches it, so the gate was not simply widened
        // into something that lets anyone in.
        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-report")
            ->assertOk();
    }

    /**
     * The scan payload carries the name on the bib and NOT the runner's contact
     * details — that is the whole reason it is not RaceEntryResource.
     */
    public function test_the_scan_payload_withholds_contact_details(): void
    {
        [$org, $event, $entry, $owner] = $this->deskSetup();

        $payload = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-scan", ['qr_code' => $entry->qr_code])
            ->assertOk()
            ->json('data.entry');

        $this->assertSame('AMMAR', $payload['full_name']);
        $this->assertArrayHasKey('category', $payload);
        foreach (['email', 'phone', 'emergency_contact_phone'] as $withheld) {
            $this->assertArrayNotHasKey($withheld, $payload);
        }
    }

    /**
     * The desk summary counts paid entries only, and the comparison is what
     * says so: an unpaid entry in the denominator is a remainder that can never
     * reach zero, because checkIn() refuses its code outright.
     */
    public function test_the_report_counts_paid_entries_only(): void
    {
        [$org, $event, $entry, $owner] = $this->deskSetup();
        $this->bankAccount($org);

        // A second, unpaid entry holding a place.
        \App\Services\PlatformSettings::put(['payment_gateway_enabled' => false], null);
        \App\Services\PlatformSettings::flush();
        $this->register($org, $event, $event->raceCategories()->firstOrFail(), 'DIMAS');

        $before = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-report")
            ->assertOk()
            ->json('data.checkin');

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-scan", ['qr_code' => $entry->qr_code])
            ->assertOk();

        $after = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-report")
            ->assertOk()
            ->json('data.checkin');

        // One paid entry, not two: the pending one is holding a quota place,
        // never a place in this count.
        $this->assertSame(['total' => 1, 'checked_in' => 0, 'remaining' => 1], $before);
        $this->assertSame(['total' => 1, 'checked_in' => 1, 'remaining' => 0], $after);
    }

    public function test_the_log_lists_only_runners_who_arrived(): void
    {
        [$org, $event, $entry, $owner] = $this->deskSetup();
        $second = $this->register($org, $event, $event->raceCategories()->firstOrFail(), 'DIMAS');

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-scan", ['qr_code' => $entry->qr_code])
            ->assertOk();

        $items = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-checkins")
            ->assertOk()
            ->json('data.items');

        // One row, not two: `$second` is paid and holds a code, and is simply
        // not here yet. Asserting a non-empty log would pass either way.
        $this->assertSame(['AMMAR'], array_column($items, 'full_name'));
        $this->assertNull($second->fresh()->checked_in_at);
    }

    /**
     * The desk does not exist for a competition event: its codes are `tickets`
     * rows that ScanController already owns.
     */
    public function test_the_race_desk_is_closed_on_a_competition_event(): void
    {
        [$org, $event, $entry, $owner] = $this->deskSetup();

        $football = $this->eventOn($org, null, ['sport_type' => 'football', 'status' => 'open']);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$football->id}/race-scan", ['qr_code' => $entry->qr_code])
            ->assertNotFound();

        // Compared against the race event, which accepts the same payload:
        // asserting the 404 alone would pass with the door shut for everyone.
        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-scan", ['qr_code' => $entry->qr_code])
            ->assertOk();
    }

    private function bankAccount(Organization $org): void
    {
        if ($org->bankAccounts()->where('is_primary', true)->exists()) {
            return;
        }

        $org->bankAccounts()->create([
            'bank_name' => 'BCA',
            'bank_code' => '014',
            'account_number' => '1234567890',
            'account_holder' => 'Flo Event EO',
            'is_primary' => true,
        ]);
    }
}
