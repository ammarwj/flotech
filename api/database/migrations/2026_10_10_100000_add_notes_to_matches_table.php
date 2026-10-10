<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan bebas organizer untuk satu laga — "dipindah ke lapangan 2", "WO karena
 * tim tamu tidak hadir", "ditunda karena hujan".
 *
 * Sebelum kolom ini satu-satunya tempat untuk menitipkan keterangan semacam itu
 * adalah `venue`, yang dirender sebagai lokasi di kartu publik dan dibaca juga
 * oleh alokator jadwal: menulis alasan di sana mengubah arti sebuah field yang
 * punya pembaca lain.
 *
 * `text`, bukan `string(255)` — ini kalimat, bukan nama lapangan. Cap panjangnya
 * ditegakkan di validasi (`max:500`), tempat ia bisa berubah tanpa migrasi.
 *
 * Nullable, jadi tiap laga yang sudah ada tidak berubah perilakunya sama sekali:
 * null = tidak ada catatan, dan halaman publik tidak merender apa pun untuknya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('venue');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
