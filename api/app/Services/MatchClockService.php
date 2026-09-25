<?php

namespace App\Services;

use App\Exceptions\MatchClockException;
use App\Models\GameMatch;
use App\Support\MatchClockRules;

/**
 * Satu-satunya yang boleh menggeser jam pertandingan.
 *
 * Alasan yang sama dengan `MatchResultService`: ada **dua pintu** yang memegang
 * stopwatch — organizer di kartu jadwalnya, dan petugas pertandingan di halaman
 * officiating — dan dua salinan aturan yang sama akan menyimpang sampai satu
 * layar menampilkan babak yang berbeda dari layar di sebelahnya.
 *
 * Yang tersimpan adalah anchor, bukan menit. `elapsedSeconds()` menghitung ulang
 * tiap pembacaan; tidak ada kolom yang harus di-tick oleh siapa pun. Pembekuan
 * saat laga meninggalkan `ongoing` **tidak** ada di sini melainkan di
 * `GameMatch::booted()`, karena status juga ditulis dari dua pintu lain yang
 * tidak tahu-menahu tentang jam.
 */
class MatchClockService
{
    /**
     * Detik yang sudah berjalan di babak ini.
     *
     * Anchor + selisih sampai sekarang saat jam berjalan; kalau tidak, cukup
     * yang sudah terkumpul. Tidak pernah negatif: jam server yang mundur (NTP
     * melompat ke belakang) akan menghasilkan selisih negatif, dan papan skor
     * yang menampilkan "-3:12" lebih buruk daripada yang berhenti sejenak.
     */
    public function elapsedSeconds(GameMatch $match): int
    {
        $base = max(0, (int) $match->clock_elapsed_seconds);

        if ($match->clock_started_at === null) {
            return $base;
        }

        return $base + max(0, (int) $match->clock_started_at->diffInSeconds(now(), absolute: false));
    }

    /**
     * Blok `clock` sebuah pertandingan, atau null saat laga ini tidak berjam.
     *
     * `server_time` ikut di sini, bukan di envelope respons: tiap pembaca jadi
     * tahan skew tanpa ada yang harus memasang plumbing envelope, dan laptop
     * venue yang jamnya meleset empat menit tidak menampilkan menit yang salah
     * justru di layar yang dipasang di lapangan.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(GameMatch $match): ?array
    {
        $rules = $this->rulesFor($match);

        if ($rules === null) {
            return null;
        }

        return [
            'period' => $match->period === null ? null : (int) $match->period,
            'periods' => $rules->periods,
            'label' => $rules->label,
            'period_minutes' => $rules->periodMinutes,
            'elapsed_seconds' => $this->elapsedSeconds($match),
            'running' => $match->clock_started_at !== null,
            'server_time' => now()->toIso8601String(),
        ];
    }

    /**
     * Jalankan jam: mulai babak 1, atau lanjutkan babak yang sedang berjalan.
     *
     * Laga `scheduled` ikut **diangkat menjadi `ongoing`**. Jam yang berjalan di
     * laga yang statusnya "belum dimulai" adalah keadaan yang saling membantah,
     * dan menyerahkannya ke dua tap terpisah berarti pasti ada layar yang
     * menampilkan keduanya bertentangan. `confirmed_at` dan bracket tidak
     * disentuh — laga `scheduled` tidak punya keduanya.
     */
    public function start(GameMatch $match): GameMatch
    {
        $this->assertClocked($match);

        if ($match->clock_started_at === null) {
            $match->clock_started_at = now();
        }

        $match->period ??= 1;

        if ($match->status !== 'ongoing') {
            $match->status = 'ongoing';
        }

        $match->save();

        return $match;
    }

    /**
     * Hentikan jam, simpan yang sudah terkumpul.
     *
     * Idempoten: menekan Jeda dua kali (dua petugas, satu laga) tidak boleh
     * melipat detik yang sama dua kali. `clock_started_at` yang sudah null
     * adalah tanda bahwa pelipatannya sudah terjadi.
     */
    public function pause(GameMatch $match): GameMatch
    {
        $this->assertClocked($match);

        if ($match->clock_started_at !== null) {
            $match->clock_elapsed_seconds = $this->elapsedSeconds($match);
            $match->clock_started_at = null;
            $match->save();
        }

        return $match;
    }

    /** Jalankan lagi dari tempat jeda. Sama dengan `start()` pada laga berjalan. */
    public function resume(GameMatch $match): GameMatch
    {
        return $this->start($match);
    }

    /**
     * Babak berikutnya: nomornya naik, jamnya kembali 0, dan langsung berjalan.
     *
     * Langsung berjalan karena tombol ini ditekan tepat saat kickoff babak dua —
     * menyuruh petugas menekan Mulai lagi sesudahnya berarti detik-detik pertama
     * babak itu hilang di tiap pertandingan.
     */
    public function advance(GameMatch $match): GameMatch
    {
        $this->assertClocked($match);

        $rules = $this->rulesFor($match);
        $next = (int) ($match->period ?? 0) + 1;

        if ($rules !== null && $next > $rules->periods) {
            throw new MatchClockException(
                "Pertandingan ini cuma punya {$rules->periods} {$rules->label}.",
            );
        }

        $match->period = $next;
        $match->clock_elapsed_seconds = 0;
        $match->clock_started_at = now();

        if ($match->status !== 'ongoing') {
            $match->status = 'ongoing';
        }

        $match->save();

        return $match;
    }

    /**
     * Jam babak ini kembali 0 — salah tekan, bukan babak baru.
     *
     * Babaknya tidak disentuh: yang dikoreksi adalah stopwatch yang menyala
     * kepagian, dan menurunkan nomor babaknya sekaligus akan mengubah dua hal
     * padahal yang salah satu.
     */
    public function reset(GameMatch $match): GameMatch
    {
        $this->assertClocked($match);

        $match->clock_elapsed_seconds = 0;
        $match->clock_started_at = $match->clock_started_at === null ? null : now();
        $match->save();

        return $match;
    }

    /**
     * Aturan babak yang berlaku untuk laga ini, atau null saat ia tidak berjam.
     *
     * Dua keadaan tak-berjam, dan keduanya null di sini supaya `snapshot()` tidak
     * perlu bercabang: cabang set (papan skornya sudah menampilkan set) dan tie
     * beregu (satu tie adalah beberapa partai, masing-masing dengan jamnya
     * sendiri — "Babak 1" di atasnya tidak menunjuk apa pun).
     */
    private function rulesFor(GameMatch $match): ?MatchClockRules
    {
        $category = $match->category;

        if ($category === null || $category->usesRubbers()) {
            return null;
        }

        $rules = MatchClockRules::forCategory($category);

        return $rules->enabled ? $rules : null;
    }

    /**
     * Penolakan yang sama di kedua pintu.
     *
     * @throws MatchClockException
     */
    private function assertClocked(GameMatch $match): void
    {
        if (in_array($match->status, ['finished', 'cancelled'], true)) {
            throw new MatchClockException('Jam pertandingan yang sudah selesai tidak bisa diubah.');
        }

        $category = $match->category;

        if ($category !== null && $category->usesRubbers()) {
            throw new MatchClockException('Pertandingan beregu dimainkan per partai, bukan per babak.');
        }

        if ($this->rulesFor($match) === null) {
            throw new MatchClockException('Cabang ini tidak memakai jam pertandingan.');
        }
    }
}
