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
 * A runner asks for their entry fee back; the organizer rules on it.
 *
 * Every test here **compares** two things that must come out different, because
 * almost nothing in this flow can be proven with one assert — the same posture
 * RaceEntryTest takes and for the same reason. "The request was filed" stays
 * green when the state is slipped into `status`, when approving never touches
 * the ledger, and when the quota is never given back.
 */
class RaceRefundRequestTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSettings::flush();
        Mail::fake();
        Carbon::setTestNow('2026-01-05 03:00:00'); // 10:00 WIB, inside Early Bird
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

    private function category(Event $event, string $name = 'Umum', float $price = 75_000): RaceCategory
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
     * A settled entry. With no Midtrans server key the suite settles on
     * registration, so this is `markPaid()`'s own output rather than a
     * hand-written row — which is what makes the wallet credit real, and the
     * credit is what the approval tests measure.
     */
    private function paidEntry(Organization $org, Event $event, RaceCategory $category): RaceEntry
    {
        $id = $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", [
                'race_category_id' => $category->id,
                'full_name' => 'Ammar',
                'email' => 'ammar@test.com',
                'phone' => '08123456789',
                'payment_channel' => 'va',
            ])
            ->json('data.entry.id');

        $entry = RaceEntry::findOrFail($id);
        app(RaceEntryService::class)->markPaid($entry);

        return $entry->fresh();
    }

    private function ask(RaceEntry $entry, string $reason = 'Cedera, tidak bisa ikut lomba')
    {
        return $this->postJson("/api/v1/race-entries/{$entry->id}/refund-request", [
            'reason' => $reason,
        ]);
    }

    // ---- filing a request ----

    /**
     * > **Invarian: "sedang diajukan" diturunkan, bukan nilai keenam di
     * > `status`.**
     *
     * Dibandingkan **entry yang sedang mengajukan dengan entry lunas biasa**:
     * `status` keduanya wajib `paid`. Assert "pengajuan tersimpan" sendirian
     * lolos penuh walau statusnya ditulis `refund_requested` — dan nilai itulah
     * yang akan melewati tiga guard `in_array($status, [...])` (sapuan
     * expire-manual, `cancel()`, `refundRaceEntry()`) tanpa error.
     */
    public function test_an_open_request_does_not_touch_the_status_column(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);
        $category = $this->category($event);

        $asking = $this->paidEntry($org, $event, $category);
        $plain = $this->paidEntry($org, $event, $category);

        $this->ask($asking)->assertOk();

        $asking->refresh();

        // The pair: one has a request, both still read `paid`.
        $this->assertSame('paid', $asking->status);
        $this->assertSame($plain->status, $asking->status);
        $this->assertNotNull($asking->refund_requested_at);
        $this->assertNull($plain->refund_requested_at);
        $this->assertTrue($asking->hasOpenRefundRequest());
        $this->assertFalse($plain->hasOpenRefundRequest());
    }

    /**
     * Pengajuan kedua ditolak selama yang pertama belum diputus — tapi boleh
     * lagi sesudah ditolak.
     *
     * Dibandingkan **keduanya dalam satu test**: assert "pengajuan kedua 422"
     * sendirian lolos walau gate-nya membaca `refund_requested_at` saja dan
     * mengunci peserta selamanya sesudah satu penolakan.
     */
    public function test_a_second_request_is_refused_while_the_first_is_open_but_allowed_after_a_refusal(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $this->ask($entry)->assertOk();
        $this->ask($entry)->assertStatus(422);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-refunds/{$entry->id}/reject", [
                'reason' => 'Sudah lewat batas',
            ])
            ->assertOk();

        // The other half of the pair: a refusal is a verdict on that request,
        // not on the entry.
        $this->ask($entry)->assertOk();
        $this->assertTrue($entry->fresh()->hasOpenRefundRequest());
        $this->assertNull($entry->fresh()->refund_rejected_reason);
    }

    /**
     * Entry yang sudah check-in tidak bisa diajukan refund — pembandingnya
     * entry lunas yang belum check-in di event yang sama.
     *
     * Assert "yang check-in 422" sendirian lolos walau gate-nya menolak semua
     * entry; yang memisahkannya adalah entry sebelahnya yang 200.
     */
    public function test_a_checked_in_entry_cannot_be_refunded_but_its_neighbour_can(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);
        $category = $this->category($event);

        $ran = $this->paidEntry($org, $event, $category);
        $didNot = $this->paidEntry($org, $event, $category);

        app(RaceEntryService::class)->checkIn($ran, null);

        $this->ask($ran->fresh())->assertStatus(422);
        $this->ask($didNot)->assertOk();

        $this->assertFalse($ran->fresh()->canRequestRefund());
        $this->assertFalse($didNot->fresh()->canRequestRefund()); // now has an open one
    }

    /**
     * Entry gratis dan entry belum lunas sama-sama ditolak, dengan pesan yang
     * berbeda — peserta bisa bertindak atas bedanya.
     */
    public function test_unpaid_and_free_entries_are_refused(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $pending = $this->paidEntry($org, $event, $this->category($event));
        $pending->update(['status' => 'pending', 'paid_at' => null]);

        $free = $this->paidEntry($org, $event, $this->category($event, 'Sponsor', 0));

        $this->ask($pending->fresh())->assertStatus(422);
        $this->ask($free)->assertStatus(422);

        // Different causes get different sentences, which is the whole reason
        // the guards are separate branches.
        $this->assertNotSame(
            $this->ask($pending->fresh())->json('message'),
            $this->ask($free)->json('message'),
        );
    }

    /** Alasan wajib — penyelenggara diminta mengembalikan uang dan ini yang ia baca. */
    public function test_the_reason_is_required(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $this->postJson("/api/v1/race-entries/{$entry->id}/refund-request", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    // ---- the organizer's verdict ----

    /**
     * > **Invarian: menyetujui melewati `RefundService`, dalam satu transaksi.**
     *
     * Dibandingkan **saldo dompet dan `race_categories.taken` sebelum/sesudah**
     * — assert `status === 'refunded'` sendirian lolos walau ledger-nya tidak
     * pernah dibalik dan kuotanya tidak pernah dikembalikan, yang berarti
     * organizer dikreditkan atas uang yang sudah pulang dan `wallet:audit`
     * tidak bisa melihatnya.
     */
    public function test_approving_reverses_the_credit_and_gives_the_place_back(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event);
        $entry = $this->paidEntry($org, $event, $category);

        $this->ask($entry)->assertOk();

        $takenBefore = $category->fresh()->taken;
        $creditBefore = WalletTransaction::where('source_type', 'race_entry')
            ->where('source_id', $entry->id)
            ->where('category', 'race_entry')
            ->count();

        // The credit really exists — otherwise "it was reversed" below would be
        // a comparison between two nothings.
        $this->assertSame(1, $creditBefore);
        $this->assertSame(1, $takenBefore);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-refunds/{$entry->id}/approve")
            ->assertOk();

        $entry->refresh();

        $this->assertSame('refunded', $entry->status);
        $this->assertNotNull($entry->refund_decided_at);
        $this->assertSame($owner->id, $entry->refund_decided_by);
        // The place is back on offer.
        $this->assertSame($takenBefore - 1, $category->fresh()->taken);
        // And the ledger moved: the credit was still pending (released only
        // after 01:00 WIB), so it is cancelled rather than debited.
        $this->assertSame('cancelled', WalletTransaction::where('source_type', 'race_entry')
            ->where('source_id', $entry->id)
            ->where('category', 'race_entry')
            ->firstOrFail()
            ->status);
    }

    /**
     * Menolak membiarkan entry lunas dan tetap memegang tempatnya.
     *
     * Dibandingkan dengan jalur setuju di test di atas: assert "ditolak"
     * sendirian lolos walau penolakan ikut membalik ledger.
     */
    public function test_rejecting_leaves_the_entry_paid_and_the_ledger_alone(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event);
        $entry = $this->paidEntry($org, $event, $category);

        $this->ask($entry)->assertOk();

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-refunds/{$entry->id}/reject", [
                'reason' => 'Nomor BIB sudah dicetak',
            ])
            ->assertOk();

        $entry->refresh();

        $this->assertSame('paid', $entry->status);
        $this->assertSame('Nomor BIB sudah dicetak', $entry->refund_rejected_reason);
        $this->assertTrue($entry->refundWasRejected());
        $this->assertFalse($entry->hasOpenRefundRequest());
        // The question the answer belongs to is still on the row.
        $this->assertNotNull($entry->refund_requested_at);
        // The place is still taken and the credit still standing.
        $this->assertSame(1, $category->fresh()->taken);
        $this->assertSame('pending', WalletTransaction::where('source_type', 'race_entry')
            ->where('source_id', $entry->id)
            ->where('category', 'race_entry')
            ->firstOrFail()
            ->status);
    }

    /** Memutus pengajuan yang tidak ada ditolak, di kedua arah. */
    public function test_deciding_without_an_open_request_is_refused(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $base = "/api/v1/organizations/{$org->id}/events/{$event->id}/race-refunds/{$entry->id}";

        $this->actingAs($owner, 'api')->postJson("{$base}/approve")->assertStatus(422);
        $this->actingAs($owner, 'api')->postJson("{$base}/reject", ['reason' => 'x'])->assertStatus(422);

        $this->assertSame('paid', $entry->fresh()->status);
    }

    /**
     * Antreannya `org.admin`, bukan `tenant`.
     *
     * **Tiga aktor satu payload** (owner / `operator` / anggota org lain →
     * 200/403/403): assert owner sukses lolos identik di bawah `tenant` biasa,
     * dan `operator` adalah petugas scan yang tidak boleh mendorong saldo
     * organizer jadi minus.
     */
    public function test_the_refund_queue_is_owner_admin_only(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $outsider = User::factory()->create();

        $org = $this->orgFor($owner);
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $event = $this->raceEvent($org);
        $this->category($event);

        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/race-refunds";

        $this->actingAs($owner, 'api')->getJson($url)->assertOk();
        $this->actingAs($operator, 'api')->getJson($url)->assertForbidden();
        $this->actingAs($outsider, 'api')->getJson($url)->assertForbidden();
    }

    /**
     * Antrean memisahkan yang menunggu dari yang sudah diputus.
     *
     * Dibandingkan **dua entry dalam satu respons**: assert "pending berisi
     * satu" sendirian lolos walau kedua daftar berisi baris yang sama.
     */
    public function test_the_queue_separates_open_requests_from_decided_ones(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event);

        $open = $this->paidEntry($org, $event, $category);
        $closed = $this->paidEntry($org, $event, $category);

        $this->ask($open)->assertOk();
        $this->ask($closed)->assertOk();

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-refunds/{$closed->id}/reject", [
                'reason' => 'Tidak memenuhi syarat',
            ])
            ->assertOk();

        $queue = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-refunds")
            ->json('data');

        $this->assertSame([$open->id], array_column($queue['pending'], 'id'));
        $this->assertSame([$closed->id], array_column($queue['decided'], 'id'));
    }

    /**
     * `can_request_refund` diterbitkan server, bukan disusun ulang klien.
     *
     * Dibandingkan **tiga keadaan dalam satu test** (lunas belum mengajukan /
     * sedang mengajukan / sudah check-in): assert "true untuk yang lunas"
     * sendirian lolos walau field-nya dipaku `status === 'paid'` dan tombolnya
     * mengirim ke 422.
     */
    public function test_the_server_publishes_whether_a_refund_may_be_asked_for(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);
        $category = $this->category($event);

        $fresh = $this->paidEntry($org, $event, $category);
        $asking = $this->paidEntry($org, $event, $category);
        $ran = $this->paidEntry($org, $event, $category);

        $this->ask($asking)->assertOk();
        app(RaceEntryService::class)->checkIn($ran, null);

        $read = fn (RaceEntry $e) => $this->getJson("/api/v1/race-entries/{$e->id}")->json('data');

        $this->assertTrue($read($fresh)['can_request_refund']);
        $this->assertFalse($read($asking)['can_request_refund']);
        $this->assertFalse($read($ran)['can_request_refund']);

        // And the state behind it, which the page renders differently in each.
        $this->assertFalse($read($fresh)['refund_pending']);
        $this->assertTrue($read($asking)['refund_pending']);
    }

    // ---- refund yang dimulai organizer ----

    private function issue(Organization $org, Event $event, RaceEntry $entry, string $reason = 'Pembayaran ganda')
    {
        return $this->postJson(
            "/api/v1/organizations/{$org->id}/events/{$event->id}/race-refunds/{$entry->id}/issue",
            ['reason' => $reason],
        );
    }

    /**
     * > **Invarian: refund terbitan organizer meninggalkan `refund_requested_at`
     * > NULL, dan null itulah yang mengatakan siapa yang memulainya.**
     *
     * Dibandingkan **jalur terbitan dengan jalur disetujui dalam satu test**:
     * keduanya mendarat di `status = 'refunded'` dengan ledger yang sama-sama
     * berbalik, jadi assert salah satunya sendirian lolos penuh walau
     * `markRefundIssued()` ikut menstempel `refund_requested_at` — dan dengan
     * stempel itu halaman peserta akan berbunyi "penyelenggara menyetujui
     * pengajuanmu" di atas refund yang tak pernah ia ajukan, serta antrean
     * organizer menampilkannya sebagai jawaban atas pertanyaan yang tak pernah
     * ada.
     */
    public function test_an_issued_refund_leaves_no_request_behind_unlike_an_approved_one(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event);

        $issued = $this->paidEntry($org, $event, $category);
        $approved = $this->paidEntry($org, $event, $category);

        $this->ask($approved, 'Cedera')->assertOk();

        $this->actingAs($owner, 'api')->postJson(
            "/api/v1/organizations/{$org->id}/events/{$event->id}/race-refunds/{$approved->id}/approve",
        )->assertOk();

        $this->actingAs($owner, 'api');
        $this->issue($org, $event, $issued)->assertOk();

        $issued->refresh();
        $approved->refresh();

        // Keduanya sampai di tempat yang sama — itu yang membuat pasangannya
        // perlu: tanpa pembanding, assert di bawah tidak membuktikan apa pun.
        $this->assertSame('refunded', $issued->status);
        $this->assertSame('refunded', $approved->status);

        // Dan inilah yang memisahkannya.
        $this->assertNull($issued->refund_requested_at);
        $this->assertNotNull($approved->refund_requested_at);

        // Alasannya ditulis di kedua jalur, cuma penulisnya beda — organizer di
        // satu sisi, peserta di sisi lain.
        $this->assertSame('Pembayaran ganda', $issued->refund_reason);
        $this->assertSame('Cedera', $approved->refund_reason);
        $this->assertSame($owner->id, $issued->refund_decided_by);
    }

    /**
     * Terbitan organizer membalik ledger dan mengembalikan tempatnya, persis
     * seperti jalur disetujui.
     *
     * Dibandingkan dengan entry lunas di sebelahnya yang **tidak** direfund:
     * assert "kredit jadi cancelled" sendirian lolos walau endpoint-nya
     * membalik setiap kredit event itu.
     */
    public function test_issuing_reverses_only_that_entrys_credit_and_frees_its_place(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event);

        $refunded = $this->paidEntry($org, $event, $category);
        $untouched = $this->paidEntry($org, $event, $category);

        $this->assertSame(2, $category->fresh()->taken);

        $this->actingAs($owner, 'api');
        $this->issue($org, $event, $refunded)->assertOk();

        $status = fn (RaceEntry $e) => WalletTransaction::where('source_type', 'race_entry')
            ->where('source_id', $e->id)
            ->where('category', 'race_entry')
            ->firstOrFail()
            ->status;

        $this->assertSame('cancelled', $status($refunded));
        // Tetangganya tidak tersentuh — itu yang mengikatnya.
        $this->assertSame('pending', $status($untouched));
        $this->assertSame('paid', $untouched->fresh()->status);
        // Satu tempat dilepas, bukan dua.
        $this->assertSame(1, $category->fresh()->taken);
    }

    /**
     * Entry yang SUDAH mengajukan ditolak di sini, dan alasan peserta tetap utuh.
     *
     * Dibandingkan dengan entry tanpa pengajuan yang berhasil: assert "yang
     * mengajukan 422" sendirian lolos walau endpoint-nya menolak semuanya.
     * Kolom `refund_reason` ikut di-assert karena itulah **sebab** penolakannya
     * — `issue()` menulis alasan organizer ke kolom yang sama, jadi lewat di
     * sini akan menimpa kata-kata peserta dan halamannya menampilkan jawaban
     * atas pertanyaan yang sudah tidak bisa ia cetak.
     */
    public function test_an_entry_with_an_open_request_is_sent_to_the_queue_instead(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event);

        $asked = $this->paidEntry($org, $event, $category);
        $didnt = $this->paidEntry($org, $event, $category);

        $this->ask($asked, 'Cedera lutut')->assertOk();

        $this->actingAs($owner, 'api');
        $this->issue($org, $event, $asked)->assertStatus(422);
        $this->issue($org, $event, $didnt)->assertOk();

        // Alasan peserta selamat, dan entry-nya masih lunas menunggu keputusan.
        $this->assertSame('Cedera lutut', $asked->fresh()->refund_reason);
        $this->assertSame('paid', $asked->fresh()->status);
        $this->assertTrue($asked->fresh()->hasOpenRefundRequest());
        $this->assertSame('refunded', $didnt->fresh()->status);
    }

    /**
     * Yang sudah check-in, yang belum lunas, dan yang gratis ditolak.
     *
     * Ketiganya satu test karena masing-masing dijaga di tempat berbeda —
     * check-in oleh `issue()`, dua sisanya oleh `RefundService` — dan assert
     * satu di antaranya lolos walau dua guard lainnya tidak pernah jalan.
     */
    public function test_issuing_is_refused_for_checked_in_unpaid_and_free_entries(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event);
        $free = $this->category($event, 'Gratis', 0);

        $ran = $this->paidEntry($org, $event, $category);
        app(RaceEntryService::class)->checkIn($ran, null);

        $unpaid = $this->paidEntry($org, $event, $category);
        $unpaid->update(['status' => 'pending', 'paid_at' => null]);

        $gratis = $this->paidEntry($org, $event, $free);
        $ok = $this->paidEntry($org, $event, $category);

        $this->actingAs($owner, 'api');

        $this->issue($org, $event, $ran)->assertStatus(422);
        $this->issue($org, $event, $unpaid)->assertStatus(422);
        $this->issue($org, $event, $gratis)->assertStatus(422);
        // Pasangannya: endpoint yang menolak segalanya lolos tanpa ini.
        $this->issue($org, $event, $ok)->assertOk();

        $this->assertSame('paid', $ran->fresh()->status);
        $this->assertSame('pending', $unpaid->fresh()->status);
        $this->assertSame('paid', $gratis->fresh()->status);
        $this->assertSame('refunded', $ok->fresh()->status);
    }

    /** Alasan wajib — pesertanya yang membacanya di halamannya sendiri. */
    public function test_issuing_requires_a_reason(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $this->actingAs($owner, 'api')
            ->postJson(
                "/api/v1/organizations/{$org->id}/events/{$event->id}/race-refunds/{$entry->id}/issue",
                ['reason' => '  '],
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertSame('paid', $entry->fresh()->status);
    }

    /**
     * Tiga aktor, satu payload: owner / `operator` / anggota org lain.
     *
     * Assert "owner sukses" lolos **identik** di bawah `tenant` saja — yang akan
     * meloloskan petugas scan untuk mendorong saldo organizer jadi minus.
     */
    public function test_issuing_is_owner_admin_only(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $outsider = User::factory()->create();

        $org = $this->orgFor($owner);
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $event = $this->raceEvent($org);
        $category = $this->category($event);
        $entry = $this->paidEntry($org, $event, $category);

        $this->actingAs($operator, 'api');
        $this->issue($org, $event, $entry)->assertForbidden();
        $this->actingAs($outsider, 'api');
        $this->issue($org, $event, $entry)->assertForbidden();

        // Tidak satu pun dari keduanya menyentuh uangnya.
        $this->assertSame('paid', $entry->fresh()->status);
        $this->assertSame(1, $category->fresh()->taken);

        $this->actingAs($owner, 'api');
        $this->issue($org, $event, $entry)->assertOk();
    }
}
