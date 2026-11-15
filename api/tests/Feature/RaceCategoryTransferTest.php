<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceCategory;
use App\Models\RaceEntry;
use App\Models\RaceEntryTransfer;
use App\Models\RefundPayout;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ParticipantDocumentService;
use App\Services\PlatformSettings;
use App\Services\RaceEntryService;
use App\Services\RaceTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * A runner moves their entry from one race category to another.
 *
 * Every test here **compares** two things that must come out different, the
 * posture RaceEntryTest and RaceRefundRequestTest already take and for the same
 * reason: almost nothing in this flow can be proven with one assert. "The
 * transfer saved" stays green when the price is read from today's window
 * instead of the entry's, when the second move overcharges, when the source
 * place is released before the money arrives, and when the entry's own receipt
 * gets rewritten underneath it.
 */
class RaceCategoryTransferTest extends TestCase
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

    /**
     * A fun run with TWO sale windows, which is what lets the pricing tests
     * compare "the entry's window" against "today's".
     */
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

        $event->racePeriods()->create([
            'name' => 'Normal',
            'starts_at' => '2026-01-11T00:00:00+07:00',
            'ends_at' => '2026-02-28T23:59:59+07:00',
            'sort_order' => 1,
        ]);

        return $event->fresh();
    }

    /**
     * A category priced per window. `$prices` is keyed by period name, so a
     * test can say "Early Bird 100rb, Normal 150rb" and the gap between them is
     * what proves which one got read.
     *
     * @param  array<string, float>  $prices
     */
    private function category(Event $event, string $name, array $prices, ?int $quota = null): RaceCategory
    {
        $category = $event->raceCategories()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'quota' => $quota,
        ]);

        foreach ($event->racePeriods as $period) {
            if (! array_key_exists($period->name, $prices)) {
                continue;
            }

            $category->prices()->create([
                'race_period_id' => $period->id,
                'price' => $prices[$period->name],
            ]);
        }

        return $category->fresh();
    }

    /**
     * A settled entry. With no Midtrans server key the suite settles on
     * registration, so this is `markPaid()`'s own output rather than a
     * hand-written row — which is what makes the wallet credit real, and the
     * credit is what the money tests measure.
     */
    private function paidEntry(Organization $org, Event $event, RaceCategory $category, string $email = 'ammar@test.com'): RaceEntry
    {
        $id = $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", [
                'race_category_id' => $category->id,
                'full_name' => 'Ammar',
                'email' => $email,
                'phone' => '08123456789',
                'payment_channel' => 'va',
            ])
            ->json('data.entry.id');

        $entry = RaceEntry::findOrFail($id);
        app(RaceEntryService::class)->markPaid($entry);

        return $entry->fresh();
    }

    /**
     * Force every move made after this onto the manual rail.
     *
     * Load-bearing for any test that needs a GENUINELY open transfer: the suite
     * runs with no Midtrans server key, so `mock` is true and a gateway top-up
     * settles the instant it is created — which is right (it is what lets
     * `paidEntry()` exist) but leaves "pending" unreachable on that rail. A
     * manual transfer must never reach the mock shortcut, so this is the only
     * way to observe a move waiting for money.
     */
    private function forceManualRail(Organization $org): void
    {
        PlatformSettings::put(['payment_gateway_enabled' => false], null);

        $org->bankAccounts()->firstOrCreate(
            ['is_primary' => true],
            ['bank_name' => 'BCA', 'account_number' => '1234567890', 'account_holder' => 'Ammar'],
        );
    }

    private function move(RaceEntry $entry, RaceCategory $target, array $extra = [])
    {
        return $this->postJson("/api/v1/race-entries/{$entry->id}/transfer", [
            'race_category_id' => $target->id,
            'payment_channel' => 'va',
            ...$extra,
        ]);
    }

    // ---- pricing ----

    /**
     * > **Invarian: harga tujuan dibaca dari periode ENTRY, bukan periode
     * > sekarang.**
     *
     * Dibandingkan **harga periode entry dengan harga periode yang sedang
     * berlaku**: pelari daftar di Early Bird, lalu pindah setelah jendela
     * bergeser ke Normal. Selisihnya wajib dihitung dari daftar harga Early
     * Bird. Assert "ada selisih tersimpan" sendirian lolos penuh di build yang
     * membaca jendela hari ini — dan di sana pelari ditagih karena menunggu.
     */
    public function test_the_target_price_comes_from_the_entrys_own_window_not_todays(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $from = $this->category($event, '5K', ['Early Bird' => 100_000, 'Normal' => 150_000]);
        $to = $this->category($event, '10K', ['Early Bird' => 160_000, 'Normal' => 300_000]);

        $entry = $this->paidEntry($org, $event, $from);

        // The clock moves into the second window. The entry does not.
        Carbon::setTestNow('2026-01-20 03:00:00');

        $this->move($entry, $to)->assertCreated();

        $transfer = RaceEntryTransfer::firstOrFail();

        // The pair: Early Bird's 160k − 100k, never Normal's 300k − 100k.
        $this->assertSame(60_000.0, (float) $transfer->amount);
        $this->assertSame(160_000.0, (float) $transfer->to_price);
        $this->assertSame('Early Bird', $transfer->period_name);
        $this->assertNotSame(200_000.0, (float) $transfer->amount);
    }

    /**
     * > **Invarian: selisih dihitung dari SELURUH yang sudah dibayar.**
     *
     * Dibandingkan **rantai 5K → 10K → 21K dengan pendaftaran langsung 21K**:
     * total belanja keduanya wajib sama. Memakai `race_entries.price` sendiri
     * lolos setiap uji satu langkah lalu menagih 100rb pertama dua kali begitu
     * ada yang pindah dua kali — assert "pindah kedua ada biayanya" lolos di
     * kedua build.
     */
    public function test_a_chain_of_moves_lands_on_the_target_price_exactly_once(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $k5 = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $k10 = $this->category($event, '10K', ['Early Bird' => 200_000]);
        $k21 = $this->category($event, '21K', ['Early Bird' => 350_000]);

        $chained = $this->paidEntry($org, $event, $k5);
        $direct = $this->paidEntry($org, $event, $k21, 'direct@test.com');

        $this->move($chained, $k10)->assertCreated();
        $this->settleOpen($chained);

        $this->move($chained->fresh(), $k21)->assertCreated();
        $this->settleOpen($chained);

        $chained->refresh();

        // The comparison: two routes to 21K, one total.
        $this->assertSame(
            (float) $direct->price,
            RaceEntryTransfer::paidTowards($chained),
        );
        // And the second move was the remainder, not the whole jump again.
        $this->assertSame(
            [100_000.0, 150_000.0],
            $chained->transfers->map(fn ($t) => (float) $t->amount)->all(),
        );
    }

    /**
     * The entry's own price column is never rewritten, so its receipt keeps
     * saying what it said.
     *
     * Dibandingkan **`price` sebelum dan sesudah pindah** terhadap total yang
     * sudah dibayar: yang pertama wajib tidak berubah sementara yang kedua
     * naik. Assert "totalnya benar" sendirian lolos di build yang menulis ulang
     * `price` — dan di sana invoice yang sudah diunduh orang berubah isinya.
     */
    public function test_moving_never_rewrites_the_entrys_own_price_or_documents(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $from = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $to = $this->category($event, '10K', ['Early Bird' => 200_000]);

        $entry = $this->paidEntry($org, $event, $from);
        $priceBefore = (float) $entry->price;
        $receiptBefore = $entry->receipt_number;
        $invoiceBefore = $entry->invoice_number;

        $this->move($entry, $to)->assertCreated();
        $this->settleOpen($entry);

        $entry->refresh();

        // The pair: the bill is frozen, the tally moved.
        $this->assertSame($priceBefore, (float) $entry->price);
        $this->assertSame($receiptBefore, $entry->receipt_number);
        $this->assertSame($invoiceBefore, $entry->invoice_number);
        $this->assertSame(200_000.0, RaceEntryTransfer::paidTowards($entry));

        // And the top-up has its OWN documents, on its own number stream.
        $transfer = RaceEntryTransfer::firstOrFail();
        $this->assertNotNull($transfer->invoice_number);
        $this->assertNotNull($transfer->receipt_number);
        $this->assertStringStartsWith('INV-RXF/', $transfer->invoice_number);
        $this->assertNotSame($entry->invoice_number, $transfer->invoice_number);
    }

    // ---- quota ----

    /**
     * > **Invarian: kuota pindah dalam dua langkah.**
     *
     * Dibandingkan **`taken` kedua kategori di TIGA titik** — sesudah transfer
     * dibuat, sesudah lunas, dan (di test berikutnya) sesudah hangus. Assert
     * keadaan akhir sendirian lolos penuh di build yang melepas kategori asal
     * lebih awal — dan di sana pelari yang gagal bayar kembali ke kategori yang
     * tempatnya sudah diambil orang lain.
     */
    public function test_the_source_place_is_held_until_the_top_up_settles(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $from = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $to = $this->category($event, '10K', ['Early Bird' => 200_000]);

        $entry = $this->paidEntry($org, $event, $from);
        // Manual, so point two below is observable at all: on the gateway rail
        // the suite's missing server key settles the move inside the same
        // request, and "both held" would never be a state anything could see.
        $this->forceManualRail($org);

        $this->assertSame([1, 0], [$from->fresh()->taken, $to->fresh()->taken]);

        $this->move($entry, $to)->assertCreated();

        // Point two: both held. The runner still owns the place they paid for.
        $this->assertSame([1, 1], [$from->fresh()->taken, $to->fresh()->taken]);

        $this->settleOpen($entry);

        // Point three: the source is free only now.
        $this->assertSame([0, 1], [$from->fresh()->taken, $to->fresh()->taken]);
        $this->assertSame($to->id, $entry->fresh()->race_category_id);
    }

    /**
     * An abandoned move gives the TARGET place back and leaves the runner
     * exactly where they were.
     *
     * Dibandingkan **kategori asal dengan kategori tujuan sesudah sapuan**:
     * tujuan kembali nol, asal tetap satu. Assert "transfer kedaluwarsa"
     * sendirian lolos walau pelarinya ikut kehilangan tempat yang sudah ia
     * bayar.
     */
    public function test_an_expired_move_releases_only_the_target_place(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $from = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $to = $this->category($event, '10K', ['Early Bird' => 200_000]);

        // Entered on the gateway rail, so the entry itself is settled; only the
        // move below is manual, which is what makes it expirable.
        $entry = $this->paidEntry($org, $event, $from);
        $this->forceManualRail($org);

        $this->move($entry, $to)->assertCreated();
        $transfer = RaceEntryTransfer::firstOrFail();
        $this->assertSame('manual', $transfer->payment_method);
        $this->assertSame([1, 1], [$from->fresh()->taken, $to->fresh()->taken]);

        Carbon::setTestNow(Carbon::now()->addDays(5));
        $this->artisan('race-transfers:expire-manual')->assertSuccessful();

        // The pair: target released, source untouched, runner unmoved.
        $this->assertSame([1, 0], [$from->fresh()->taken, $to->fresh()->taken]);
        $this->assertSame('expired', $transfer->fresh()->status);
        $this->assertSame($from->id, $entry->fresh()->race_category_id);
    }

    // ---- the bib ----

    /**
     * > **Invarian: `bib_number` dibuang saat pindah, `qr_code` tetap.**
     *
     * Dibandingkan **keduanya dalam satu test**: nomornya ber-prefix slug
     * kategori ("5K-001"), jadi membawanya ke 10K berarti memegang nomor yang
     * mengaku kategori lain — sementara QR adalah kredensial orangnya dan
     * mencabutnya mematikan kode yang sudah ada di ponselnya. Assert salah
     * satunya lolos di build yang membuang keduanya, atau tidak membuang apa
     * pun.
     */
    public function test_a_move_drops_the_category_stamped_bib_and_keeps_the_qr(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $from = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $to = $this->category($event, '10K', ['Early Bird' => 200_000]);

        $entry = $this->paidEntry($org, $event, $from);
        $entry->update(['bib_number' => '5K-001', 'bib_png_key' => 'bibs/x.png', 'bib_sent_at' => Carbon::now()]);
        $qrBefore = $entry->fresh()->qr_code;
        $this->assertNotNull($qrBefore);

        $this->move($entry, $to)->assertCreated();
        $this->settleOpen($entry);

        $entry->refresh();

        // The pair.
        $this->assertNull($entry->bib_number);
        $this->assertNull($entry->bib_png_key);
        $this->assertNull($entry->bib_sent_at);
        $this->assertSame($qrBefore, $entry->qr_code);
    }

    // ---- money ----

    /**
     * Moving up credits the organizer for the difference; moving down debits
     * them and queues the transfer back.
     *
     * Dibandingkan **kedua arah dalam satu test**, dan yang diperiksa adalah
     * **baris kredit asli** di samping baris baru: kredit entry wajib tidak
     * tersentuh di kedua arah. Assert "saldo berubah" sendirian lolos di build
     * yang mengedit baris kredit asli — dan di sana `wallet:audit` berhenti
     * rekonsiliasi tanpa ada yang tahu.
     */
    public function test_up_credits_the_difference_and_down_debits_it_without_touching_the_original_credit(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $k5 = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $k10 = $this->category($event, '10K', ['Early Bird' => 200_000]);

        $up = $this->paidEntry($org, $event, $k5);
        $down = $this->paidEntry($org, $event, $k10, 'down@test.com');

        $originalUp = WalletTransaction::where('source_id', $up->id)->firstOrFail();
        $originalDown = WalletTransaction::where('source_id', $down->id)->firstOrFail();

        $this->move($up, $k10)->assertCreated();
        $this->settleOpen($up);

        $this->move($down, $k5)->assertCreated();

        $upTransfer = $up->fresh()->transfers->first();
        $downTransfer = $down->fresh()->transfers->first();

        // Up: a credit of the difference, its own category and source.
        $credit = WalletTransaction::where('source_id', $upTransfer->id)->firstOrFail();
        $this->assertSame('credit', $credit->type);
        $this->assertSame('race_entry_transfer', $credit->category);
        $this->assertSame(100_000.0, (float) $credit->amount);

        // Down: a debit of the difference, and a payout row queued for it.
        $debit = WalletTransaction::where('source_id', $downTransfer->id)->firstOrFail();
        $this->assertSame('debit', $debit->type);
        $this->assertSame(100_000.0, (float) $debit->amount);
        $this->assertDatabaseHas('refund_payouts', [
            'source_type' => 'race_entry_transfer',
            'source_id' => $downTransfer->id,
            'amount' => '100000.00',
            'status' => 'pending',
        ]);
        // Read from the ledger row, never recomputed — the refund_payouts
        // invariant.
        $this->assertSame(
            (float) $debit->amount,
            (float) RefundPayout::where('source_id', $downTransfer->id)->firstOrFail()->amount,
        );

        // The pair that matters: neither original credit was edited.
        $this->assertSame((float) $originalUp->amount, (float) $originalUp->fresh()->amount);
        $this->assertSame((float) $originalDown->amount, (float) $originalDown->fresh()->amount);
        $this->assertNotSame('cancelled', $originalDown->fresh()->status);
    }

    /**
     * A move to a cheaper category applies AT ONCE — there is nothing to
     * collect — and a free-or-manual entry's difference writes no payout row.
     *
     * Dibandingkan **rail gateway dengan rail manual**: keduanya memindahkan
     * pelarinya, cuma yang pertama mengantre transfer balik. Assert "barisnya
     * ada untuk yang gateway" sendirian lolos walau setiap refund menulis satu —
     * dan di sana super_admin melihat utang transfer atas uang yang tak pernah
     * dipegang platform.
     */
    public function test_a_downward_move_settles_immediately_and_only_platform_money_queues_a_payout(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $k5 = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $k10 = $this->category($event, '10K', ['Early Bird' => 200_000]);

        $gateway = $this->paidEntry($org, $event, $k10);

        // A manual-rail entry: the money went to the organizer's own account,
        // so the platform never held it and owes no transfer.
        $manual = $this->paidEntry($org, $event, $k10, 'manual@test.com');
        $manual->update(['payment_method' => 'manual']);
        WalletTransaction::where('source_id', $manual->id)->delete();

        $this->move($gateway, $k5)->assertCreated();
        $this->move($manual->fresh(), $k5)->assertCreated();

        $gatewayTransfer = $gateway->fresh()->transfers->first();
        $manualTransfer = $manual->fresh()->transfers->first();

        // Both applied on the spot: nothing was owed by the runner.
        $this->assertSame('paid', $gatewayTransfer->status);
        $this->assertSame('paid', $manualTransfer->status);
        $this->assertSame($k5->id, $gateway->fresh()->race_category_id);
        $this->assertSame($k5->id, $manual->fresh()->race_category_id);
        // No document either way: a Rp 0 bill claims a transaction that never
        // happened.
        $this->assertNull($gatewayTransfer->invoice_number);

        // The pair: one owes a transfer back, one cannot.
        $this->assertDatabaseHas('refund_payouts', ['source_id' => $gatewayTransfer->id]);
        $this->assertDatabaseMissing('refund_payouts', ['source_id' => $manualTransfer->id]);
    }

    /**
     * An equal-priced move costs nothing, applies at once, and issues no
     * document.
     *
     * Dibandingkan **dengan pindah yang berbayar**: keduanya memindahkan
     * pelarinya, cuma satu yang punya tagihan. Assert "pindah berhasil"
     * sendirian lolos walau pelarinya ditinggalkan di halaman pembayaran Rp 0.
     */
    public function test_an_equal_priced_move_needs_no_payment(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $a = $this->category($event, '5K Pagi', ['Early Bird' => 100_000]);
        $b = $this->category($event, '5K Siang', ['Early Bird' => 100_000]);
        $dearer = $this->category($event, '10K', ['Early Bird' => 200_000]);

        $even = $this->paidEntry($org, $event, $a);
        $paying = $this->paidEntry($org, $event, $a, 'pay@test.com');

        // Manual from here, so the paying half stays genuinely open — see
        // forceManualRail(). The even half is settled by `start()` whatever the
        // rail, which is exactly the difference being measured.
        $this->forceManualRail($org);

        $this->move($even, $b)->assertCreated();
        $this->move($paying, $dearer)->assertCreated();

        $evenTransfer = $even->fresh()->transfers->first();
        $payingTransfer = $paying->fresh()->transfers->first();

        // The pair.
        $this->assertSame('even', $evenTransfer->direction);
        $this->assertSame('paid', $evenTransfer->status);
        $this->assertSame(0.0, (float) $evenTransfer->amount);
        $this->assertNull($evenTransfer->invoice_number);
        $this->assertSame($b->id, $even->fresh()->race_category_id);

        $this->assertSame('up', $payingTransfer->direction);
        $this->assertSame('pending', $payingTransfer->status);
        $this->assertNotNull($payingTransfer->invoice_number);
        // Still where they started: an unpaid move moves nobody.
        $this->assertSame($a->id, $paying->fresh()->race_category_id);
    }

    /**
     * > **Invarian: the gateway fee is taken from the DIFFERENCE, after it is
     * > computed.**
     *
     * Dibandingkan **di channel persentase (`ewallet`)**, pindah 100rb lawan
     * pindah 250rb: fee-nya wajib berbeda sebanding. Di `va` fee-nya flat, jadi
     * perbandingan di sana tidak bisa membedakan build yang benar dari yang
     * menghitung fee atas harga penuh kategori tujuan.
     */
    public function test_the_gateway_fee_is_a_percentage_of_the_difference_not_the_target_price(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $base = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $small = $this->category($event, '10K', ['Early Bird' => 200_000]);
        $big = $this->category($event, '21K', ['Early Bird' => 350_000]);

        $a = $this->paidEntry($org, $event, $base);
        $b = $this->paidEntry($org, $event, $base, 'b@test.com');

        $this->move($a, $small, ['payment_channel' => 'ewallet'])->assertCreated();
        $this->move($b, $big, ['payment_channel' => 'ewallet'])->assertCreated();

        $small = $a->fresh()->transfers->first();
        $big = $b->fresh()->transfers->first();

        $this->assertSame(100_000.0, (float) $small->amount);
        $this->assertSame(250_000.0, (float) $big->amount);

        // The comparison: the fee scales with the difference. A build charging
        // on the target price would read 200k vs 350k — a ratio of 1.75, not 2.5.
        $this->assertGreaterThan(0, (float) $small->gateway_fee);
        $this->assertSame(
            round((float) $small->gateway_fee * 2.5, 2),
            round((float) $big->gateway_fee, 2),
        );
    }

    // ---- guards ----

    /**
     * A second move is refused while the first is open.
     *
     * Dibandingkan **permintaan kedua sebelum dan sesudah yang pertama lunas**:
     * yang sebelum ditolak, yang sesudah diterima. Assert 422 sendirian lolos
     * walau pelarinya terkunci selamanya sesudah satu kali pindah.
     */
    public function test_a_second_move_waits_for_the_first_to_settle(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $k5 = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $k10 = $this->category($event, '10K', ['Early Bird' => 200_000]);
        $k21 = $this->category($event, '21K', ['Early Bird' => 350_000]);

        $entry = $this->paidEntry($org, $event, $k5);
        // Manual, so the first move really stays open — on the gateway rail the
        // suite's missing server key settles it instantly and there would be no
        // open move for the guard to refuse.
        $this->forceManualRail($org);

        $this->move($entry, $k10)->assertCreated();
        // While the first is open.
        $this->move($entry->fresh(), $k21)->assertStatus(422);

        $this->settleOpen($entry);

        // And after it settles.
        $this->move($entry->fresh(), $k21)->assertCreated();
        $this->assertCount(2, $entry->fresh()->transfers);
    }

    /**
     * An unpaid entry and a checked-in one are both refused, and the messages
     * differ because the runner can act on the difference.
     *
     * Dibandingkan **ketiganya dalam satu test** (lunas / belum lunas /
     * sudah check-in): assert satu penolakan sendirian lolos di build yang
     * menolak semua orang.
     */
    public function test_only_a_paid_uncheckedin_entry_may_move(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $k5 = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $k10 = $this->category($event, '10K', ['Early Bird' => 200_000]);

        $ok = $this->paidEntry($org, $event, $k5);

        $unpaid = $this->paidEntry($org, $event, $k5, 'unpaid@test.com');
        $unpaid->update(['status' => 'pending']);

        $ran = $this->paidEntry($org, $event, $k5, 'ran@test.com');
        $ran->update(['checked_in_at' => Carbon::now()]);

        // The triple.
        $this->move($ok, $k10)->assertCreated();
        $this->move($unpaid->fresh(), $k10)->assertStatus(422);
        $this->move($ran->fresh(), $k10)->assertStatus(422);

        $this->assertFalse($unpaid->fresh()->canTransferCategory());
        $this->assertFalse($ran->fresh()->canTransferCategory());
    }

    /**
     * A category not sold in the entry's window is refused, not quoted at zero.
     *
     * Dibandingkan **kategori yang dijual di periode entry dengan yang tidak**:
     * `priceFor()` mengembalikan null untuk yang kedua, dan null bukan nol —
     * mengartikannya nol memindahkan orang gratis. Assert "kategori pertama
     * diterima" sendirian lolos di build yang membaca null sebagai 0.
     */
    public function test_a_category_not_sold_in_the_entrys_window_is_refused(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $from = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $sold = $this->category($event, '10K', ['Early Bird' => 200_000]);
        // Normal only: this category was not on offer during Early Bird.
        $notSold = $this->category($event, '21K', ['Normal' => 350_000]);

        $a = $this->paidEntry($org, $event, $from);
        $b = $this->paidEntry($org, $event, $from, 'b@test.com');

        // The pair.
        $this->move($a, $sold)->assertCreated();
        $this->move($b, $notSold)->assertStatus(422);

        // And it is not offered either, so nothing is shown then refused.
        $offered = $this->getJson("/api/v1/race-entries/{$b->id}/transfer-options")
            ->assertOk()
            ->json('data.options');

        $this->assertSame(
            ['10K'],
            array_column(array_column($offered, 'category'), 'name'),
        );
    }

    /**
     * A full target category is refused, and so is the entry's own.
     *
     * Dibandingkan **kategori penuh dengan kategori yang masih punya tempat**:
     * assert salah satunya lolos di build yang tidak memeriksa kuota sama
     * sekali.
     */
    public function test_a_full_target_and_the_current_category_are_both_refused(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $from = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $full = $this->category($event, '10K', ['Early Bird' => 200_000], quota: 1);
        $open = $this->category($event, '21K', ['Early Bird' => 350_000]);

        $entry = $this->paidEntry($org, $event, $from);
        // Somebody else takes the only 10K place.
        $this->paidEntry($org, $event, $full, 'other@test.com');

        // The triple: full, same, and one that works.
        $this->move($entry, $full)->assertStatus(422);
        $this->move($entry->fresh(), $from)->assertStatus(422);
        $this->move($entry->fresh(), $open)->assertCreated();
    }

    /**
     * The target category's email condition is enforced here too.
     *
     * Dibandingkan **pelari ber-email kampus dengan pelari umum** pada kategori
     * yang sama: assert satu penolakan sendirian lolos di build yang tidak
     * memeriksa domain dari pintu ini — dan di sana syarat kategori mahasiswa
     * bisa dilewati dari pintu samping.
     */
    public function test_the_targets_email_condition_still_applies_when_moving_in(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $open = $this->category($event, 'Umum', ['Early Bird' => 100_000]);
        $students = $this->category($event, 'Mahasiswa', ['Early Bird' => 80_000]);
        $students->update(['email_domains' => ['ub.ac.id']]);

        $outsider = $this->paidEntry($org, $event, $open, 'ammar@gmail.com');
        $student = $this->paidEntry($org, $event, $open, 'ammar@ub.ac.id');

        // The pair.
        $this->move($outsider, $students->fresh())->assertStatus(422);
        $this->move($student, $students->fresh())->assertCreated();
    }

    /**
     * An entry whose sale window was deleted is refused rather than quoted off
     * today's prices.
     *
     * Dibandingkan **entry berperiode dengan entry yang periodenya dihapus**:
     * assert "yang pertama bisa pindah" sendirian lolos di build yang jatuh ke
     * jendela sekarang — dan selisihnya bisa sebesar apa pun.
     */
    public function test_an_entry_with_no_surviving_period_cannot_be_quoted(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $from = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $to = $this->category($event, '10K', ['Early Bird' => 200_000, 'Normal' => 300_000]);

        $withPeriod = $this->paidEntry($org, $event, $from);
        $orphan = $this->paidEntry($org, $event, $from, 'orphan@test.com');
        // nullOnDelete: the snapshot survives, the FK does not.
        $orphan->update(['race_period_id' => null]);

        // The pair.
        $this->move($withPeriod, $to)->assertCreated();
        $this->move($orphan->fresh(), $to)->assertStatus(422);

        $this->assertFalse($orphan->fresh()->canTransferCategory());
        // The snapshot is still readable, which is the point of keeping it.
        $this->assertSame('Early Bird', $orphan->fresh()->period_name);
    }

    // ---- documents ----

    /**
     * A settled top-up's documents are its own, and they are reachable.
     *
     * Dibandingkan **dokumen top-up dengan dokumen entry**: nomornya wajib
     * beda, dan keduanya wajib tetap bisa diunduh. Assert "kwitansi top-up
     * terbit" sendirian lolos di build yang tidak pernah menerbitkan
     * `transfer_history` — dan di sana dokumennya ada di URL yang tidak bisa
     * ditemukan siapa pun, karena kwitansi entry sengaja tidak menyebutnya.
     *
     * Diperiksa pada **HTML**-nya, bukan byte PDF: output dompdf adalah stream
     * terkompresi, jadi assert atasnya tetap hijau saat barisnya hilang —
     * alasan yang sama dengan `TicketPosterService::html()`.
     */
    public function test_a_settled_top_up_has_its_own_reachable_documents(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $from = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $to = $this->category($event, '10K', ['Early Bird' => 200_000]);

        $entry = $this->paidEntry($org, $event, $from);
        $this->move($entry, $to)->assertCreated();
        $this->settleOpen($entry);

        $transfer = RaceEntryTransfer::firstOrFail();

        // Reachable: the history is what carries the numbers to a screen.
        $history = $this->getJson("/api/v1/race-entries/{$entry->id}")
            ->assertOk()
            ->json('data.transfer_history');

        $this->assertCount(1, $history);
        $this->assertSame($transfer->id, $history[0]['id']);
        $this->assertSame($transfer->receipt_number, $history[0]['receipt_number']);

        // Both documents still download, on separate number streams.
        $this->get("/api/v1/race-transfers/{$transfer->id}/receipt")->assertOk();
        $this->get("/api/v1/race-entries/{$entry->id}/receipt")->assertOk();
        $this->assertNotSame($entry->fresh()->receipt_number, $transfer->receipt_number);

        // The pair that proves the bill is a DIFFERENCE: the top-up's document
        // prints 100k, the entry's still prints its own 100k — and the top-up
        // names both categories so the figure can be checked against something.
        $html = app(ParticipantDocumentService::class)->html('receipt', $transfer);

        $this->assertStringContainsString('5K', $html);
        $this->assertStringContainsString('10K', $html);
        $this->assertStringContainsString('Early Bird', $html);
        // The arithmetic is spelled out, so the target price DOES appear — as
        // the thing being subtracted from, which is the whole point of the note.
        $this->assertStringContainsString('Rp 200.000', $html);
        $this->assertStringContainsString('sudah dibayar Rp 100.000', $html);
        // What must be the difference is the amount BILLED, and the total row
        // is where that is settled. Asserting on "Rp 100.000" appearing
        // anywhere would pass on a build printing the target price as the
        // total, because the note above mentions both figures — so this reads
        // the row, and reads it against the row's OWN gross (the difference
        // plus its fees) rather than a literal, which is what keeps the
        // assertion honest when a fee rate moves.
        $this->assertSame(100_000.0, (float) $transfer->amount);
        $this->assertMatchesRegularExpression(
            '/Total.*?'.preg_quote(number_format($transfer->gross_amount, 0, ',', '.'), '/').'/s',
            $html,
        );
        // And the top-up is nowhere near the target category's full price.
        $this->assertLessThan(200_000.0, (float) $transfer->gross_amount);
    }

    /**
     * An even or downward move issues no document at all.
     *
     * Dibandingkan **dengan top-up yang berbayar**: assert "yang berbayar punya
     * invoice" sendirian lolos di build yang menerbitkan tagihan Rp 0 — dan itu
     * dokumen yang mengklaim transaksi yang tidak pernah terjadi.
     */
    public function test_a_downward_move_issues_no_document(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $k5 = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $k10 = $this->category($event, '10K', ['Early Bird' => 200_000]);

        $down = $this->paidEntry($org, $event, $k10);
        $up = $this->paidEntry($org, $event, $k5, 'up@test.com');

        $this->move($down, $k5)->assertCreated();
        $this->move($up, $k10)->assertCreated();
        $this->settleOpen($up);

        $downTransfer = $down->fresh()->transfers->first();
        $upTransfer = $up->fresh()->transfers->first();

        // The pair.
        $this->assertNull($downTransfer->invoice_number);
        $this->assertNull($downTransfer->receipt_number);
        $this->assertNotNull($upTransfer->receipt_number);

        $this->get("/api/v1/race-transfers/{$downTransfer->id}/receipt")->assertNotFound();
        $this->get("/api/v1/race-transfers/{$upTransfer->id}/receipt")->assertOk();
    }

    // ---- the organizer's queue ----

    /**
     * An org admin accepting a manual top-up is what moves the runner; a gate
     * operator cannot do it.
     *
     * Diuji dengan **tiga aktor satu payload** (owner / `operator` / anggota org
     * lain → 200/403/403). Assert owner sukses lolos identik di bawah `tenant`
     * biasa, dan di sana petugas scan bisa memindahkan peserta antar kategori.
     */
    public function test_approving_a_manual_top_up_moves_the_runner_and_needs_org_admin(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);

        $from = $this->category($event, '5K', ['Early Bird' => 100_000]);
        $to = $this->category($event, '10K', ['Early Bird' => 200_000]);

        $entry = $this->paidEntry($org, $event, $from);
        $this->forceManualRail($org);

        $this->move($entry, $to)->assertCreated();
        $transfer = RaceEntryTransfer::firstOrFail();

        $this->postJson("/api/v1/race-transfers/{$transfer->id}/proof", [
            'payment_proof_url' => 'https://example.test/bukti.jpg',
        ])->assertOk();

        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);
        $stranger = User::factory()->create();
        $this->orgFor($stranger);

        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/payments/race-transfers/{$transfer->id}/approve";

        // The triple.
        $this->actingAs($stranger, 'api')->postJson($url)->assertStatus(403);
        $this->actingAs($operator, 'api')->postJson($url)->assertStatus(403);
        $this->actingAs($owner, 'api')->postJson($url)->assertOk();

        // And the approval is what moved them.
        $this->assertSame('paid', $transfer->fresh()->status);
        $this->assertSame($to->id, $entry->fresh()->race_category_id);
        $this->assertSame([0, 1], [$from->fresh()->taken, $to->fresh()->taken]);
    }

    // ---- helpers ----

    /**
     * Settle whatever move this entry has open, the way a Midtrans webhook
     * would.
     *
     * Through the service rather than a bare update, because the whole point of
     * these tests is what settlement DOES — move the category, release the
     * source place, drop the bib, touch the ledger.
     */
    private function settleOpen(RaceEntry $entry): void
    {
        $open = $entry->fresh()->transfers->first(fn (RaceEntryTransfer $t) => $t->isOpen());

        if ($open) {
            app(RaceTransferService::class)->settle($open);
        }
    }
}
