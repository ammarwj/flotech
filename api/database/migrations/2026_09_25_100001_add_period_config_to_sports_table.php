<?php

use App\Services\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Berapa babak sebuah cabang dimainkan, dan berapa lama tiap babaknya.
 *
 * Nilainya **diturunkan dari `default_match_minutes` yang sudah ada** supaya
 * tidak ada durasi yang tersimpan dua kali dengan angka yang berbeda: sepak bola
 * 90 → 2×45, mini soccer 50 → 2×25, futsal 40 → 2×20.
 *
 * Setelah migrasi ini keduanya berpisah, dan itu disengaja.
 * `default_match_minutes` adalah **jarak antar slot di generator jadwal** — ia
 * memang memuat turun minum dan waktu bersih lapangan — sementara
 * `period_minutes` adalah **waktu bermain**. Menurunkannya sekali di sini memberi
 * angka awal yang benar; mengikatnya selamanya akan membuat organizer yang
 * melebarkan slot jadwalnya ikut memperpanjang babak.
 *
 * Cabang set (voli, badminton, tenis, tenis meja, padel) dapat null: papan
 * skornya sudah menampilkan set, dan "Babak 1" di sana salah.
 *
 * Menyemai sendiri, bukan menyuruh menjalankan ulang `SportSeeder` di prod —
 * seeder itu akan menimpa editan admin pada kolom cabang lainnya (caveat yang
 * sama sudah tertulis di `create_sport_official_roles_table`).
 */
return new class extends Migration
{
    /**
     * Cabang yang tidak bisa dijawab dengan "separuh durasinya".
     *
     * Basket dimainkan empat kuarter, dan kata "Babak" salah di sana. Sisanya
     * diturunkan dari `default_match_minutes`.
     *
     * Dipelihara sejalan dengan `MatchClockRules::DEFAULTS` dengan tangan alih-alih
     * di-import: sebuah migrasi adalah catatan tentang apa yang kolom itu berisi
     * pada hari ia dijalankan, dan menunjuknya ke konstanta yang nanti berubah akan
     * menulis ulang sejarah.
     *
     * @var array<string, array{periods: int, period_minutes: int, label: string}>
     */
    private const OVERRIDES = [
        'basketball' => ['periods' => 4, 'period_minutes' => 10, 'label' => 'Kuarter'],
    ];

    public function up(): void
    {
        Schema::table('sports', function (Blueprint $table) {
            $table->json('period_config')->nullable()->after('discipline_config');
        });

        // Hanya cabang berskor lari. Cabang set tidak punya babak, dan memberinya
        // config akan membuat fitur ini mengklaim menyala di tempat ia tidak bisa
        // pernah dipakai — alasan yang sama dengan `discipline_config` yang cuma
        // diberikan ke cabang yang membooking pemain.
        $goals = DB::table('sports')
            ->where('scoring', 'goal')
            ->get(['id', 'slug', 'default_match_minutes']);

        foreach ($goals as $sport) {
            $config = self::OVERRIDES[$sport->slug] ?? [
                'periods' => 2,
                // max(1): sebuah cabang dengan durasi ganjil kecil tidak boleh
                // menghasilkan babak 0 menit, yang akan membuat offset babak 2
                // menjadi 0 dan jam babak keduanya mulai dari nol lagi.
                'period_minutes' => max(1, (int) round(((int) $sport->default_match_minutes) / 2)),
                'label' => 'Babak',
            ];

            DB::table('sports')
                ->where('id', $sport->id)
                ->update(['period_config' => json_encode($config)]);
        }

        // Katalog diingat selamanya; tanpa ini kolom barunya tidak terlihat sampai
        // ada tulisan admin yang kebetulan mem-flush-nya.
        Catalog::flush();
    }

    public function down(): void
    {
        Schema::table('sports', function (Blueprint $table) {
            $table->dropColumn('period_config');
        });

        Catalog::flush();
    }
};
