<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventCategory;
use App\Services\Catalog;

/**
 * Berapa babak sebuah pertandingan dimainkan dan berapa lama tiap babaknya,
 * diselesaikan untuk satu kategori.
 *
 * Tiga lapis, di-merge dangkal per key: fallback di bawah, lalu default cabang
 * (`sports.period_config`, bisa diedit di /admin/sports), lalu override event
 * sendiri (`events.rules_config['clock']`). Organizer yang menjalankan turnamen
 * 2×25 menit mengubahnya sekali; tidak ada orang lain yang harus memikirkannya.
 *
 * `enabled` **bukan setting**. Ia menjawab "apakah cabang ini dihitung dengan
 * jam berjalan?" dan dibaca dari katalog: `scoring === 'goal'`. Alasan yang sama
 * dengan `DisciplineRules::enabled` yang membaca role stat alih-alih daftar
 * slug — cabang yang besok ditambahkan admin langsung ikut tanpa deploy, dan
 * cabang set tidak pernah salah menyala (papan skornya sudah menampilkan set,
 * dan "Babak 1" di sana salah).
 *
 * Jamnya **selalu naik dari 0**. Tidak ada opsi hitung mundur: jam dua arah
 * menggandakan tiap cabang di `MatchClockService` dan di `web/lib/match-clock.ts`
 * sekaligus, dan basket yang biasa menghitung mundur tetap terbaca benar karena
 * kuarternya cuma 10 menit. Kalau nanti diminta, tempatnya satu key di sini.
 */
final class MatchClockRules
{
    /**
     * Bentuk yang dipakai sebuah cabang saat tidak ada yang berkata lain: dua
     * babak 45 menit, disebut "Babak".
     */
    public const DEFAULTS = [
        'periods' => 2,
        'period_minutes' => 45,
        'label' => 'Babak',
    ];

    private function __construct(
        /** Apakah cabang ini punya jam sama sekali? Sisanya tidak berarti saat false. */
        public readonly bool $enabled,
        /** Jumlah babak. Tidak pernah 0 — `advance()` membandingkannya sebagai batas atas. */
        public readonly int $periods,
        /** Durasi satu babak, dipakai sebagai offset presentasi babak ke-2 dan seterusnya. */
        public readonly int $periodMinutes,
        /** "Babak" / "Kuarter" — kata yang mendahului nomornya di papan skor. */
        public readonly string $label,
    ) {}

    public static function forCategory(EventCategory $category): self
    {
        return self::forEvent($category->event, $category->sport_type);
    }

    public static function forEvent(?Event $event, ?string $slug): self
    {
        $overrides = $event?->rules_config['clock'] ?? null;

        return self::forSport($slug, is_array($overrides) ? $overrides : []);
    }

    /**
     * @param  array<string, mixed>  $eventOverrides  isi events.rules_config['clock']
     */
    public static function forSport(?string $slug, array $eventOverrides = []): self
    {
        $merged = [
            ...self::DEFAULTS,
            ...self::clean(Catalog::periodConfig($slug)),
            ...self::clean($eventOverrides),
        ];

        $label = trim((string) $merged['label']);

        return new self(
            enabled: ! Catalog::isSetBased($slug) && Catalog::sport($slug) !== null,
            // Dijaga, bukan dipercaya: 0 babak akan membuat `start()` membuka
            // babak yang langsung melewati batasnya sendiri.
            periods: max(1, (int) $merged['periods']),
            periodMinutes: max(1, (int) $merged['period_minutes']),
            label: $label !== '' ? $label : self::DEFAULTS['label'],
        );
    }

    /**
     * Aturannya sebagaimana dibaca klien, atau null saat cabangnya tak berjam.
     *
     * @return array<string, mixed>|null
     */
    public function toArray(): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        return [
            'periods' => $this->periods,
            'period_minutes' => $this->periodMinutes,
            'label' => $this->label,
        ];
    }

    /**
     * Aturan validasi untuk satu rulebook, dipakai bersama oleh master cabang dan
     * kedua request event supaya ketiganya tidak bisa berselisih.
     *
     * @return array<string, array<int, string|int>>
     */
    public static function validationRules(string $prefix): array
    {
        return [
            $prefix.'periods' => ['nullable', 'integer', 'min:1', 'max:10'],
            $prefix.'period_minutes' => ['nullable', 'integer', 'min:1', 'max:120'],
            $prefix.'label' => ['nullable', 'string', 'max:24'],
        ];
    }

    /**
     * Buang null dan apa pun yang tidak dikenali sebelum di-merge.
     *
     * Null-nya penting: field yang dikosongkan di form event berarti "ikut default
     * cabang", dan ia datang sebagai null. Di-merge apa adanya ia justru menimpa
     * lapis di bawahnya dengan null alih-alih menyerah kepadanya.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function clean(array $raw): array
    {
        return array_filter(
            array_intersect_key($raw, self::DEFAULTS),
            fn ($value) => $value !== null && $value !== '',
        );
    }
}
