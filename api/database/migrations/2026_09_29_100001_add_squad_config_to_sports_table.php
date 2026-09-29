<?php

use App\Services\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Berapa pemain inti dan cadangan yang boleh dituliskan di satu team sheet.
 *
 * Hanya cabang berskor lari yang diisi — gate yang sama dengan `period_config`
 * di sebelahnya, dan dengan `discipline_config` sebelum itu. Cabang set tidak
 * menurunkan susunan "inti vs cadangan", dan memberinya config akan membuat
 * fitur ini mengklaim menyala di tempat ia tidak pernah bisa dipakai.
 *
 * Menyemai sendiri, bukan menyuruh menjalankan ulang `SportSeeder` di prod —
 * seeder itu akan menimpa editan admin pada kolom cabang lainnya (caveat yang
 * sama sudah tertulis di `create_sport_official_roles_table` dan
 * `add_period_config_to_sports_table`).
 */
return new class extends Migration
{
    /**
     * Jumlah di lapangan per cabang, pada hari migrasi ini dijalankan.
     *
     * Ditulis penuh alih-alih men-default-kan sisanya ke sebelas: futsal dan
     * basket bukan sepak bola dengan lapangan lebih kecil, dan angka yang salah
     * di sini akan menolak sheet yang benar sampai ada admin yang menyadarinya.
     *
     * Dipelihara sejalan dengan `SquadRules::DEFAULTS` dengan tangan alih-alih
     * di-import: sebuah migrasi adalah catatan tentang apa yang kolom itu berisi
     * pada hari ia dijalankan, dan menunjuknya ke konstanta yang nanti berubah
     * akan menulis ulang sejarah. Alasan yang sama sudah tertulis di
     * `add_period_config_to_sports_table`.
     *
     * @var array<string, array{starters: int, max_substitutes: int}>
     */
    private const SQUADS = [
        'football' => ['starters' => 11, 'max_substitutes' => 7],
        'mini_soccer' => ['starters' => 7, 'max_substitutes' => 5],
        'futsal' => ['starters' => 5, 'max_substitutes' => 9],
        'basketball' => ['starters' => 5, 'max_substitutes' => 7],
    ];

    public function up(): void
    {
        Schema::table('sports', function (Blueprint $table) {
            $table->json('squad_config')->nullable()->after('period_config');
        });

        $goals = DB::table('sports')->where('scoring', 'goal')->get(['id', 'slug']);

        foreach ($goals as $sport) {
            // Cabang berskor lari yang ditambahkan admin sendiri tidak ada di
            // peta ini; ia jatuh ke DEFAULTS lewat SquadRules, dan itu memang
            // yang diinginkan — {} berarti "punya susunan, ikut default",
            // sementara null berarti "tidak punya susunan sama sekali".
            if (! isset(self::SQUADS[$sport->slug])) {
                continue;
            }

            DB::table('sports')
                ->where('id', $sport->id)
                ->update(['squad_config' => json_encode(self::SQUADS[$sport->slug])]);
        }

        // Katalog diingat selamanya; tanpa ini kolom barunya tidak terlihat
        // sampai ada tulisan admin yang kebetulan mem-flush-nya.
        Catalog::flush();
    }

    public function down(): void
    {
        Schema::table('sports', function (Blueprint $table) {
            $table->dropColumn('squad_config');
        });

        Catalog::flush();
    }
};
