<?php

namespace App\Support;

use App\Models\EventCategory;
use App\Services\Catalog;

/**
 * Berapa pemain yang boleh dituliskan manajer di satu team sheet, diselesaikan
 * untuk satu kategori.
 *
 * Tiga lapis, di-merge dangkal per key: fallback di bawah, lalu default cabang
 * (`sports.squad_config`, bisa diedit di /admin/sports), lalu override event
 * sendiri (`events.rules_config['squad']`). Pola yang sama persis dengan
 * `DisciplineRules` dan `MatchClockRules` — turnamen 7-a-side mengubahnya
 * sekali, dan tidak ada orang lain yang harus memikirkannya.
 *
 * `enabled` **bukan setting**. Ia menjawab "apakah cabang ini menurunkan susunan
 * pemain sama sekali?" dan dibaca dari katalog: `scoring === 'goal'`. Alasan yang
 * sama dengan `MatchClockRules::enabled`, dan gate-nya sengaja sama supaya tidak
 * ada cabang yang punya jam tapi tidak punya susunan, atau sebaliknya. Cabang set
 * — voli, badminton, tenis, tenis meja, padel — tidak lewat sini: yang beregu di
 * antaranya (voli) memang punya enam di lapangan, tapi rotasi dan libero bukan
 * "inti vs cadangan" dan angka yang dipasang di sini akan berbohong tentangnya.
 * Kalau nanti voli diminta, tempatnya `squad_config` miliknya sendiri dan gate
 * ini yang dilonggarkan, **bukan** aturan kedua di sebelahnya.
 *
 * > **Invarian: maks saat menyimpan, tepat saat mengirim.** `starters` adalah
 * > satu angka yang dibaca dua kali dengan dua operator, dan itulah keseluruhan
 * > alasannya ada satu angka: draf setengah jadi harus bisa disimpan (manajer
 * > mengisi sambil menunggu kabar pemain), tapi yang diserahkan ke wasit harus
 * > lengkap. Menyimpannya sebagai dua key (`min_starters`/`max_starters`) memberi
 * > dua angka yang bisa berselisih dan tidak satu pun yang menjawab "sebelas itu
 * > berapa". Bandingkan keduanya saat menguji: assert `submit()` menolak 10 saja
 * > akan lolos walau `sync()` diam-diam ikut menolaknya dan manajer tidak pernah
 * > bisa menyimpan pekerjaan setengah jadi.
 */
final class SquadRules
{
    /**
     * Bentuk yang dipakai cabang berskor lari saat tidak ada yang berkata lain:
     * sebelas di lapangan, tujuh di bangku.
     *
     * Angka sepak bola, karena itu cabang mayoritas di platform ini dan cabang
     * lain yang berbeda (futsal, basket) menyimpan angkanya sendiri di
     * `sports.squad_config` — persis seperti `period_config` menyimpan kuarter
     * basket alih-alih menjadikannya cabang di sini.
     */
    public const DEFAULTS = [
        'starters' => 11,
        'max_substitutes' => 7,
    ];

    private function __construct(
        /** Apakah cabang ini punya susunan pemain sama sekali? Sisanya tidak berarti saat false. */
        public readonly bool $enabled,
        /**
         * Pemain inti. Batas atas saat `sync()`, jumlah persis saat `submit()`.
         * Tidak pernah 0 — sheet tanpa satu pun pemain inti bukan sheet.
         */
        public readonly int $starters,
        /**
         * Cadangan maksimum. **Boleh 0**, dan 0 berarti "tidak ada bangku",
         * bukan "tanpa batas" — dibaca perbandingan biasa, jadi tidak ada yang
         * menggantung. Nilai absen di sini tidak mungkin terbaca unlimited
         * karena `DEFAULTS` selalu jadi lapis terbawah.
         */
        public readonly int $maxSubstitutes,
    ) {}

    public static function forCategory(EventCategory $category): self
    {
        $overrides = $category->event?->rules_config['squad'] ?? null;

        return self::forSport($category->sport_type, is_array($overrides) ? $overrides : []);
    }

    /**
     * @param  array<string, mixed>  $eventOverrides  isi events.rules_config['squad']
     */
    public static function forSport(?string $slug, array $eventOverrides = []): self
    {
        $merged = [
            ...self::DEFAULTS,
            ...self::clean(Catalog::squadConfig($slug)),
            ...self::clean($eventOverrides),
        ];

        return new self(
            // Gate yang sama persis dengan MatchClockRules, dan disengaja: sebuah
            // cabang yang punya jam berjalan adalah cabang yang menurunkan sebelas
            // nama sebelum kick-off. Dua gate yang berbeda akan berselisih dan
            // selisihnya baru terlihat saat admin menambahkan cabang baru.
            enabled: ! Catalog::isSetBased($slug) && Catalog::sport($slug) !== null,
            // Dijaga, bukan dipercaya: 0 inti akan membuat submit() menuntut sheet
            // kosong sementara sync() menolak setiap nama — manajer terkunci di
            // luar formulirnya sendiri tanpa satu pun pesan yang bisa ditindak.
            starters: max(1, (int) $merged['starters']),
            maxSubstitutes: max(0, (int) $merged['max_substitutes']),
        );
    }

    /** Batas atas seluruh sheet, dipakai editor untuk satu kalimat ringkas. */
    public function maxPlayers(): int
    {
        return $this->starters + $this->maxSubstitutes;
    }

    /**
     * Aturan sebagaimana dibaca klien, atau null saat cabang tidak punya susunan.
     *
     * Null-lah yang menjadi sinyal editor untuk tidak merender apa pun soal
     * batas — bentuk yang sama dengan `DisciplineRules::toArray()`.
     *
     * @return array<string, mixed>|null
     */
    public function toArray(): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        return [
            'starters' => $this->starters,
            'max_substitutes' => $this->maxSubstitutes,
            'max_players' => $this->maxPlayers(),
        ];
    }

    /**
     * Aturan validasi untuk satu rulebook, dipakai bersama master cabang dan
     * kedua request event supaya ketiganya tidak bisa menyimpang.
     *
     * @return array<string, array<int, string>>
     */
    public static function validationRules(string $prefix): array
    {
        return [
            $prefix.'starters' => ['nullable', 'integer', 'min:1', 'max:30'],
            // min:0, bukan min:1 — 0 adalah cara organizer mengatakan
            // "tidak ada bangku", dan itu keadaan yang sah.
            $prefix.'max_substitutes' => ['nullable', 'integer', 'min:0', 'max:30'],
        ];
    }

    /**
     * Buang null dan apa pun yang tidak dikenali sebelum di-merge.
     *
     * Null-nya yang penting: field yang dikosongkan di form event berarti "ikut
     * default cabang", dan ia datang sebagai null. Di-merge apa adanya ia justru
     * menimpa lapis di bawahnya dengan null alih-alih menyerah padanya.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function clean(array $raw): array
    {
        return array_filter(
            array_intersect_key($raw, self::DEFAULTS),
            fn ($value) => $value !== null,
        );
    }
}
