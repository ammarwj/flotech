<?php

namespace Tests\Feature;

use App\Exceptions\PaymentException;
use App\Models\Event;
use App\Models\RaceCategory;
use App\Models\User;
use App\Support\RacePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Harga entry fun run: periode mana yang berlaku, dan berapa harganya.
 *
 * Hampir tidak ada di sini yang bisa dibuktikan dengan satu assert. "Harga
 * early bird benar" tetap hijau walau periodenya diambil dari payload, walau
 * celah antar periode ikut membacanya, dan walau baris harga yang absen
 * terbaca 0. Karena itu tiap uji **membandingkan** dua keadaan yang harus
 * keluar berbeda: di dalam jendela vs di celahnya, kategori berharga vs
 * kategori tanpa baris harga, satu instan vs instan sesudah batas.
 */
class RacePricingTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- fixtures ----

    private function raceEvent(): Event
    {
        $owner = User::factory()->create();

        return $this->eventOn($this->orgFor($owner), null, [
            'sport_type' => 'fun_run',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-01',
        ]);
    }

    /**
     * Tiga jendela yang bersambung rapat, lalu satu celah sebelum yang terakhir
     * — celahnya sengaja: ia yang membedakan "tutup" dari "jatuh ke terdekat".
     *
     * early   : 1–10 Jan
     * presale : 11–20 Jan
     * (celah  : 21 Jan)
     * last    : 22–31 Jan
     */
    private function periodsFor(Event $event): void
    {
        foreach ([
            ['Early Bird', '2026-01-01T00:00:00+07:00', '2026-01-10T23:59:59+07:00', 0],
            ['Pre Sale', '2026-01-11T00:00:00+07:00', '2026-01-20T23:59:59+07:00', 1],
            ['Last Call', '2026-01-22T00:00:00+07:00', '2026-01-31T23:59:59+07:00', 2],
        ] as [$name, $from, $to, $order]) {
            $event->racePeriods()->create([
                'name' => $name,
                'starts_at' => $from,
                'ends_at' => $to,
                'sort_order' => $order,
            ]);
        }
    }

    /**
     * @param  array<string, float>  $prices  period name => price; a name left
     *                                        out gets no row at all, which is
     *                                        how "not sold then" is said.
     */
    private function categoryFor(Event $event, string $name, array $prices): RaceCategory
    {
        $category = $event->raceCategories()->create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
        ]);

        foreach ($prices as $periodName => $price) {
            $period = $event->racePeriods()->where('name', $periodName)->firstOrFail();
            $category->prices()->create(['race_period_id' => $period->id, 'price' => $price]);
        }

        return $category->load(['prices', 'event.racePeriods']);
    }

    private function pricing(): RacePricing
    {
        return app(RacePricing::class);
    }

    private function at(string $wib): Carbon
    {
        return Carbon::parse($wib);
    }

    // ---- which period is in force ----

    public function test_the_clock_picks_the_period_and_a_gap_picks_none(): void
    {
        $event = $this->raceEvent();
        $this->periodsFor($event);
        $event->load('racePeriods');

        $pricing = $this->pricing();

        // Dibandingkan, bukan diperiksa satu-satu: assert "early bird terbaca"
        // sendirian akan lolos walau periodAt() mengembalikan baris pertama
        // untuk instan apa pun.
        $this->assertSame('Early Bird', $pricing->periodAt($event, $this->at('2026-01-05T10:00:00+07:00'))?->name);
        $this->assertSame('Pre Sale', $pricing->periodAt($event, $this->at('2026-01-15T10:00:00+07:00'))?->name);
        $this->assertSame('Last Call', $pricing->periodAt($event, $this->at('2026-01-25T10:00:00+07:00'))?->name);

        // Separuh yang mengikat: celah tanggal 21 bukan milik siapa pun. Tanpa
        // ini, "memilih periode terdekat" lolos setiap assert di atasnya.
        $this->assertNull($pricing->periodAt($event, $this->at('2026-01-21T10:00:00+07:00')));
        // Begitu juga sebelum jendela pertama dan sesudah yang terakhir.
        $this->assertNull($pricing->periodAt($event, $this->at('2025-12-31T23:00:00+07:00')));
        $this->assertNull($pricing->periodAt($event, $this->at('2026-02-01T00:30:00+07:00')));
    }

    /**
     * Batas jendela inklusif di kedua ujung, dan detik terakhirnya yang
     * membuktikannya. Organizer mengetik angka bulat, jadi justru di sanalah
     * trafiknya menumpuk.
     */
    public function test_both_ends_of_a_window_are_inclusive(): void
    {
        $event = $this->raceEvent();
        $this->periodsFor($event);
        $event->load('racePeriods');

        $pricing = $this->pricing();

        $this->assertSame('Early Bird', $pricing->periodAt($event, $this->at('2026-01-01T00:00:00+07:00'))?->name);
        $this->assertSame('Early Bird', $pricing->periodAt($event, $this->at('2026-01-10T23:59:59+07:00'))?->name);
        // Dan satu detik sesudahnya sudah pindah — itu yang memisahkan
        // "inklusif" dari "jendelanya kelebihan sehari".
        $this->assertSame('Pre Sale', $pricing->periodAt($event, $this->at('2026-01-11T00:00:00+07:00'))?->name);
    }

    /**
     * Jendela disimpan sebagai instan UTC, bukan jam dinding mentah.
     *
     * Tanpa set-only Attribute di RacePeriod, 00:00 WIB mendarat apa adanya di
     * kolom UTC dan terbaca 17:00 hari sebelumnya — jendelanya bergeser tujuh
     * jam dan early bird berakhir di sore yang salah. Ujinya membandingkan
     * instan tersimpan dengan instan yang sama dieja UTC.
     */
    public function test_window_bounds_are_stored_as_utc_instants(): void
    {
        $event = $this->raceEvent();

        $period = $event->racePeriods()->create([
            'name' => 'Early Bird',
            'starts_at' => '2026-01-01T00:00:00+07:00',
            'ends_at' => '2026-01-10T23:59:59+07:00',
            'sort_order' => 0,
        ]);

        // 00:00 WIB adalah 17:00 UTC tanggal 31 Desember. Kalau yang tersimpan
        // "2026-01-01 00:00:00", jam dindingnya ikut tersimpan dan ini merah.
        $this->assertSame('2025-12-31 17:00:00', $period->fresh()->starts_at->utc()->toDateTimeString());
        $this->assertSame('2026-01-10 16:59:59', $period->fresh()->ends_at->utc()->toDateTimeString());
    }

    // ---- what it costs ----

    /**
     * Invarian baris absen: tidak dijual, bukan gratis.
     *
     * Dibandingkan dalam satu test — kategori yang punya baris harga di periode
     * berjalan vs kategori yang barisnya tidak ada. Assert "harga early bird
     * benar" saja akan lolos walau baris absen dibaca 0.
     */
    public function test_a_missing_price_row_means_not_sold_not_free(): void
    {
        $event = $this->raceEvent();
        $this->periodsFor($event);

        $sold = $this->categoryFor($event, 'Umum', ['Early Bird' => 75_000, 'Pre Sale' => 90_000]);
        // Sengaja tanpa baris untuk Early Bird: kategori ini baru dijual nanti.
        $notYet = $this->categoryFor($event, 'Mahasiswa', ['Pre Sale' => 65_000]);

        $pricing = $this->pricing();
        $early = $pricing->periodAt($event->load('racePeriods'), $this->at('2026-01-05T10:00:00+07:00'));

        $this->assertSame(75_000.0, $pricing->priceFor($sold, $early));
        // Separuh yang mengikat. `assertNull`, bukan `assertSame(0.0, …)`:
        // nol adalah harga yang sah (lomba gratis), jadi 0 tidak bisa
        // dibedakan dari "tidak dijual" kalau yang absen dibaca nol.
        $this->assertNull($pricing->priceFor($notYet, $early));
    }

    public function test_quote_refuses_a_gap_and_accepts_a_live_window(): void
    {
        $event = $this->raceEvent();
        $this->periodsFor($event);
        $category = $this->categoryFor($event, 'Umum', [
            'Early Bird' => 75_000,
            'Pre Sale' => 90_000,
            'Last Call' => 110_000,
        ]);

        $pricing = $this->pricing();

        // Di dalam jendela: terkutip.
        $quote = $pricing->quote($category, $this->at('2026-01-15T10:00:00+07:00'));
        $this->assertSame('Pre Sale', $quote['period']->name);
        $this->assertSame(90_000.0, $quote['price']);

        // Di celahnya: ditolak. Tanpa pasangan ini, quote() yang memilih
        // periode terdekat lolos assert di atasnya.
        $this->expectException(PaymentException::class);
        $pricing->quote($category, $this->at('2026-01-21T10:00:00+07:00'));
    }

    public function test_quote_refuses_a_category_with_no_price_this_period(): void
    {
        $event = $this->raceEvent();
        $this->periodsFor($event);
        $category = $this->categoryFor($event, 'Mahasiswa', ['Pre Sale' => 65_000]);

        $pricing = $this->pricing();

        // Jendela sedang berjalan — jadi yang menolak ini bukan jamnya,
        // melainkan tidak adanya baris harga. Dua sebab berbeda, dan test ini
        // memisahkannya dari yang di atas.
        $this->assertNotNull($pricing->periodAt($event->load('racePeriods'), $this->at('2026-01-05T10:00:00+07:00')));

        try {
            $pricing->quote($category, $this->at('2026-01-05T10:00:00+07:00'));
            $this->fail('Kategori tanpa harga periode ini seharusnya ditolak.');
        } catch (PaymentException $e) {
            // Pesannya menyebut kategori, bukan jam: pendaftar bisa bertindak
            // atas bedanya dengan memilih kategori lain.
            $this->assertArrayHasKey('race_category_id', $e->errors() ?? []);
        }

        // Dan di periode yang memang dijual, kategori yang sama diterima.
        $this->assertSame(
            65_000.0,
            $pricing->quote($category, $this->at('2026-01-15T10:00:00+07:00'))['price'],
        );
    }

    /**
     * Harga naik sepanjang rantai periode. Diuji sebagai deret, bukan satu
     * titik: satu assert tidak bisa membedakan "periode dibaca" dari "harga
     * pertama selalu dikembalikan".
     */
    public function test_price_follows_the_period_across_the_whole_chain(): void
    {
        $event = $this->raceEvent();
        $this->periodsFor($event);
        $category = $this->categoryFor($event, 'Umum', [
            'Early Bird' => 75_000,
            'Pre Sale' => 90_000,
            'Last Call' => 110_000,
        ]);

        $pricing = $this->pricing();

        $this->assertSame(75_000.0, $pricing->quote($category, $this->at('2026-01-05T08:00:00+07:00'))['price']);
        $this->assertSame(90_000.0, $pricing->quote($category, $this->at('2026-01-15T08:00:00+07:00'))['price']);
        $this->assertSame(110_000.0, $pricing->quote($category, $this->at('2026-01-25T08:00:00+07:00'))['price']);
    }

    /**
     * Tanpa `$at`, jam server yang dipakai — dan itulah satu-satunya sumbernya.
     *
     * Dua panggilan identik dipisah `setTestNow()` melintasi batas periode;
     * harganya wajib berbeda. Assert satu harga saja akan lolos walau
     * periodenya diambil dari payload.
     */
    public function test_the_server_clock_is_what_picks_the_period(): void
    {
        $event = $this->raceEvent();
        $this->periodsFor($event);
        $category = $this->categoryFor($event, 'Umum', [
            'Early Bird' => 75_000,
            'Pre Sale' => 90_000,
        ]);

        $pricing = $this->pricing();

        Carbon::setTestNow($this->at('2026-01-05T08:00:00+07:00'));
        $first = $pricing->quote($category);

        Carbon::setTestNow($this->at('2026-01-15T08:00:00+07:00'));
        $second = $pricing->quote($category);

        $this->assertSame(75_000.0, $first['price']);
        $this->assertSame(90_000.0, $second['price']);
    }

    // ---- the published schedule ----

    /**
     * Deret harga yang dibaca halaman publik: setiap periode ikut, termasuk
     * yang kategorinya tidak dijual, dan tepat satu baris bertanda berjalan.
     */
    public function test_schedule_publishes_every_period_including_unsold_ones(): void
    {
        $event = $this->raceEvent();
        $this->periodsFor($event);
        $category = $this->categoryFor($event, 'Mahasiswa', [
            'Pre Sale' => 65_000,
            'Last Call' => 80_000,
        ]);

        $rows = $this->pricing()->schedule($category, $this->at('2026-01-15T08:00:00+07:00'));

        // Tiga baris, bukan dua: periode yang tidak dijual tetap terbit dengan
        // harga null. Membuangnya akan terbaca sebagai periode yang tidak ada,
        // dan calon pendaftar yang menunggu justru butuh melihatnya.
        $this->assertCount(3, $rows);
        $this->assertNull($rows[0]['price']);
        $this->assertSame(65_000.0, $rows[1]['price']);

        // Tepat satu yang berjalan, dan ia yang benar. Assert "ada yang
        // berjalan" saja akan lolos walau semuanya ditandai.
        $this->assertSame(
            ['Pre Sale'],
            array_column(array_filter($rows, fn ($r) => $r['is_current']), 'name'),
        );
    }

    public function test_schedule_marks_nothing_current_inside_a_gap(): void
    {
        $event = $this->raceEvent();
        $this->periodsFor($event);
        $category = $this->categoryFor($event, 'Umum', ['Early Bird' => 75_000]);

        $rows = $this->pricing()->schedule($category, $this->at('2026-01-21T08:00:00+07:00'));

        $this->assertCount(3, $rows);
        $this->assertSame([], array_filter($rows, fn ($r) => $r['is_current']));
    }
}
