<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Player;
use App\Models\Plan;
use App\Models\Team;
use App\Services\Catalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Satu pertandingan yang sedang BERLANGSUNG, untuk melihat papan skor.
 *
 *   php artisan db:seed --class=LiveMatchSeeder
 *
 * Papan skor (`/{org}/{event}/scoreboard/{matchId}`) cuma hidup untuk fixture
 * ber-status `ongoing`, dan status itu satu-satunya hal yang tidak bisa dibuat
 * oleh seeder demo mana pun: DemoEventSeeder menutup tiap laga yang dimainkannya
 * (`finished`), sisanya `scheduled`. Jadi seeder ini dibuat — tanpanya papan
 * skornya hanya bisa dilihat dengan mengubah status laga sungguhan lewat UI.
 *
 * **Idempoten, dan itu yang membuatnya aman dijalankan ulang.** Organisasi,
 * event, kategori, dan tim dicari dulu lewat slug/nama sebelum dibuat, dan
 * fixture-nya dicari lewat pasangan tim + jam. Menjalankannya dua kali tidak
 * melahirkan event kedua yang namanya sama, dan tidak menumpuk laga duplikat
 * di jadwal yang sama.
 *
 * **Skornya hidup.** `ongoing` + skor yang sudah ada adalah satu-satunya
 * kombinasi yang membuat papan skor menampilkan angka alih-alih "VS" — lihat
 * `showScore` di halamannya, aturan yang sama dengan PublicMatchCard. Tambahkan
 * `GOAL=3-2` untuk mengubah skornya, lalu segarkan papan: ia mem-poll tiap 10
 * detik, jadi angkanya akan berganti sendiri di layar tanpa disentuh.
 *
 *   GOAL=3-2 php artisan db:seed --class=LiveMatchSeeder   # ubah skor berjalan
 *   FINISH=1 php artisan db:seed --class=LiveMatchSeeder   # peluit panjang
 *
 * `FINISH=1` ada karena polling papan skor berhenti sendiri di `finished` —
 * itu perilaku yang perlu bisa dilihat, bukan cuma dibaca di kodenya.
 */
class LiveMatchSeeder extends Seeder
{
    /** Kickoff yang diminta: Jumat, 25 September 2026 · 16:00 WITA. */
    private const KICKOFF = '2026-09-25 16:00:00';

    private const TIMEZONE = 'Asia/Makassar';

    private const HOME = 'AFO FC';

    private const AWAY = 'TANAH DATAR FC';

    public function run(): void
    {
        $org = $this->organization();
        $event = $this->event($org);
        $category = $this->category($event);

        $home = $this->team($event, $category, self::HOME);
        $away = $this->team($event, $category, self::AWAY);

        $match = $this->match($event, $category, $home, $away);

        $this->report($org, $event, $match);
    }

    /**
     * Organisasi penyelenggara. Dipakai ulang kalau sudah ada — seeder ini
     * dijalankan berkali-kali selama mengerjakan papan skornya.
     */
    private function organization(): Organization
    {
        $owner = \App\Models\User::where('email', 'owner@floevent.id')->first();

        return Organization::firstOrCreate(
            ['slug' => 'mbu-cup-muara-badak'],
            [
                'name' => 'MBU CUP MUARA BADAK',
                'owner_id' => $owner?->id,
                'contact_email' => $owner?->email ?? 'owner@floevent.id',
            ],
        );
    }

    /**
     * Event yang memuat laganya.
     *
     * `status` wajib di luar `draft`: seluruh permukaan publik lewat
     * `ResolvesPublicEvent::resolve()`, yang mem-404-kan draf — papan skornya
     * ikut, jadi event draf akan memberi "Pertandingan tidak ditemukan" yang
     * terbaca seperti bug di halaman yang baru saja dibuat.
     *
     * `timezone` Asia/Makassar karena jam yang diminta WITA, dan papan skor
     * mencetak jam kickoff dengan zona event — bukan zona penonton. Tanpa ini
     * layar di venue akan menulis 15:00.
     */
    private function event(Organization $org): Event
    {
        $kickoff = Carbon::parse(self::KICKOFF, self::TIMEZONE);

        return Event::firstOrCreate(
            ['organization_id' => $org->id, 'slug' => 'mbu-cup-ii-2026'],
            [
                'name' => 'MBU CUP II 2026',
                'sport_type' => 'football',
                'status' => 'ongoing',
                'timezone' => self::TIMEZONE,
                // Rentang yang memuat kickoff-nya: dana dompet baru cair setelah
                // `end_date` lewat, jadi event yang "selesai" di tanggal laga
                // yang sedang berjalan akan mencairkan uang di tengah turnamen.
                'start_date' => $kickoff->copy()->subDays(20)->toDateString(),
                'end_date' => $kickoff->copy()->addDays(6)->toDateString(),
                'registration_open' => $kickoff->copy()->subDays(45),
                'registration_close' => $kickoff->copy()->subDays(25),
                'location_name' => 'Lapangan Muara Badak',
                'location_address' => 'Muara Badak, Kutai Kartanegara',
                'plan_id' => Plan::where('slug', 'pro')->value('id') ?? Plan::value('id'),
            ],
        );
    }

    private function category(Event $event): EventCategory
    {
        return EventCategory::firstOrCreate(
            ['event_id' => $event->id, 'slug' => 'open'],
            [
                'name' => 'Open',
                'participant_type' => 'team',
                'tournament_format' => 'knockout_single',
                'registration_fee' => 0,
                'sort_order' => 0,
            ],
        );
    }

    /**
     * Satu klub beserta skuadnya.
     *
     * Dicari lewat nama di dalam event ini, bukan dibuat mentah: `firstOrCreate`
     * pada nama sajalah yang membuat seeder ini tidak melahirkan AFO FC kedua
     * setiap kali dijalankan, dan papan skor tidak punya cara menunjukkan bahwa
     * ada dua tim bernama sama.
     */
    private function team(Event $event, EventCategory $category, string $name): Team
    {
        $team = Team::firstOrCreate(
            ['event_id' => $event->id, 'name' => $name],
            [
                'category_id' => $category->id,
                'contact_name' => 'Manajer '.Str::title(Str::before($name, ' ')),
                'contact_phone' => '0811'.random_int(1000000, 9999999),
                'status' => 'approved',
                'registered_at' => now()->subDays(30),
                'approved_at' => now()->subDays(29),
                'payment_status' => 'paid',
                'payment_amount' => 0,
                'platform_fee' => 0,
            ],
        );

        if (! $team->players()->exists()) {
            $this->squad($team, $event->sport_type);
        }

        return $team;
    }

    /**
     * Sebelas pemain berikut cadangannya.
     *
     * Posisinya diambil dari master cabangnya (`Catalog::positionKeys`), bukan
     * kosakata sendiri — alasan yang sama sudah ditulis di DemoEventSeeder:
     * dropdown roster memvalidasi terhadap master itu dan akan menolak nilai
     * yang diarang seeder.
     */
    private function squad(Team $team, ?string $sport): void
    {
        $names = [
            'Rizky Ramadhan', 'Bagas Pratama', 'Dimas Saputra', 'Fajar Nugroho',
            'Andi Setiawan', 'Yoga Permana', 'Reza Maulana', 'Hendra Wijaya',
            'Galih Santoso', 'Iqbal Firmansyah', 'Bayu Kurniawan', 'Teguh Hidayat',
            'Arif Gunawan', 'Surya Halim', 'Aldi Susanto', 'Naufal Ramadhan',
        ];

        $positions = Catalog::positionKeys($sport);

        foreach ($names as $i => $full) {
            Player::create([
                'team_id' => $team->id,
                'full_name' => $full,
                'jersey_number' => (string) ($i + 1),
                'position' => $positions === [] ? null : $positions[array_rand($positions)],
                'is_active' => true,
            ]);
        }
    }

    /**
     * Fixture-nya, dalam keadaan yang membuat papan skornya hidup.
     *
     * Dicari lewat pasangan tim + jam kickoff, bukan dibuat setiap kali:
     * menjalankan ulang seeder ini tidak boleh menumpuk laga kembar di jadwal
     * yang sama. Kalau laganya sudah ada — termasuk yang lahir dari jadwal
     * sungguhan — ia yang dipakai dan statusnya diperbarui, bukan disaingi oleh
     * laga baru.
     *
     * `confirmed_at` tetap null selama berlangsung: konfirmasi berarti hasil
     * resmi, dan itulah yang dibaca klasemen, leaderboard, serta akumulasi
     * kartu. Mengonfirmasi laga yang masih berjalan akan memasukkan skor
     * sementara ke tabel klasemen.
     */
    private function match(Event $event, EventCategory $category, Team $home, Team $away): GameMatch
    {
        $kickoff = Carbon::parse(self::KICKOFF, self::TIMEZONE)->utc();
        $finish = (bool) env('FINISH');

        $match = GameMatch::firstOrCreate(
            [
                'event_id' => $event->id,
                'home_team_id' => $home->id,
                'away_team_id' => $away->id,
                'scheduled_at' => $kickoff,
            ],
            [
                'category_id' => $category->id,
                // Laga penentu di kategori knockout: `stage` null adalah yang
                // benar di sini, dan di knockout_single memang semua laga
                // ber-stage null — lihat catatan `matches.stage` di CLAUDE.md.
                'round' => 1,
                'order' => 1,
                'leg' => 1,
                'venue' => 'Lapangan Muara Badak',
            ],
        );

        [$homeScore, $awayScore] = $this->score($match);

        $match->update([
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'sets' => null,
            'status' => $finish ? 'finished' : 'ongoing',
            // Hasil resmi hanya untuk laga yang sudah usai; lihat docblock.
            'confirmed_at' => $finish ? now() : null,
        ]);

        return $match->fresh();
    }

    /**
     * Skor berjalan: dari `GOAL=3-2`, atau skor yang sudah ada.
     *
     * Tanpa `GOAL` skor laganya **dipertahankan**, bukan dikembalikan ke
     * bawaan. Kalau tidak, `FINISH=1` — yang gunanya cuma meniup peluit panjang
     * — diam-diam menulis ulang 3-2 jadi 2-1, dan laga yang "selesai" di layar
     * berakhir dengan skor yang tidak pernah dimainkan. Bawaannya hanya berlaku
     * untuk fixture yang memang belum berskor.
     *
     * @return array{0: int, 1: int}
     */
    private function score(GameMatch $match): array
    {
        $raw = trim((string) env('GOAL', ''));

        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $raw, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return $match->home_score !== null && $match->away_score !== null
            ? [(int) $match->home_score, (int) $match->away_score]
            : [2, 1];
    }

    private function report(Organization $org, Event $event, GameMatch $match): void
    {
        $web = rtrim((string) config('app.frontend_url'), '/');
        $kickoff = Carbon::parse($match->scheduled_at)->setTimezone(self::TIMEZONE);

        $this->command?->info("Pertandingan {$match->status}: ".self::HOME." {$match->home_score} – {$match->away_score} ".self::AWAY);
        $this->command?->line('  Kickoff  : '.$kickoff->translatedFormat('l, j F Y · H:i').' WITA');
        $this->command?->line("  Event    : {$web}/{$org->slug}/{$event->slug}");
        $this->command?->line("  Papan skor: {$web}/{$org->slug}/{$event->slug}/scoreboard/{$match->id}");
        $this->command?->newLine();
        $this->command?->line('  Ubah skor : GOAL=3-2 php artisan db:seed --class=LiveMatchSeeder');
        $this->command?->line('  Selesaikan: FINISH=1 php artisan db:seed --class=LiveMatchSeeder');
    }
}
