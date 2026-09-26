<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Babak dan jam pertandingan — apa yang papan skor di pinggir lapangan tampilkan
 * selain skor.
 *
 * Yang tersimpan di sini adalah *anchor*, bukan menit. `clock_started_at` adalah
 * instant saat jam terakhir dijalankan dan `clock_elapsed_seconds` adalah yang
 * sudah terkumpul sebelum jeda terakhir; menitnya dihitung ulang tiap pembacaan.
 * Sebuah kolom "menit ke-67" akan basi persis saat papan ditinggal menyala, yang
 * justru satu-satunya keadaan papan ini dipakai — kelas bug yang sama dengan
 * larangan bermain yang disimpan alih-alih diturunkan (lihat `DisciplineService`).
 *
 * `clock_elapsed_seconds` dihitung **per babak**, bukan kumulatif seumur laga.
 * Babak 2 sepak bola tampil mulai 45:00, tapi itu offset presentasi yang
 * diturunkan dari aturan; menyimpannya kumulatif berarti mengubah durasi babak di
 * aturan event akan menggeser menit laga yang sudah selesai.
 *
 * Ketiganya nullable/berdefault, jadi tiap laga yang sudah ada tidak berubah
 * perilakunya sama sekali: `period` null = belum ada babak yang dijalankan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->unsignedTinyInteger('period')->nullable()->after('status');
            $table->timestamp('clock_started_at')->nullable()->after('period');
            $table->unsignedInteger('clock_elapsed_seconds')->default(0)->after('clock_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['period', 'clock_started_at', 'clock_elapsed_seconds']);
        });
    }
};
