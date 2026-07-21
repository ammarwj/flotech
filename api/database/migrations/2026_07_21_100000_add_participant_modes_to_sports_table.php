<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which shapes of entrant a sport can be run with: a lone player (tunggal), a
 * pair (ganda), or a squad (tim). Racket sports support all three; football and
 * volleyball only ever field a squad.
 *
 * Every existing row is squad-only, so nothing that already runs changes shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sports', function (Blueprint $table) {
            $table->json('participant_modes')->nullable()->after('scoring');
        });

        DB::table('sports')->update(['participant_modes' => json_encode(['team'])]);
    }

    public function down(): void
    {
        Schema::table('sports', function (Blueprint $table) {
            $table->dropColumn('participant_modes');
        });
    }
};
