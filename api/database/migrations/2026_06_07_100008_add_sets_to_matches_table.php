<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // Per-set scores for set-based sports: [{"home":25,"away":20}, ...].
            // For goal-based sports this stays null and home/away_score are goals.
            $table->json('sets')->nullable()->after('away_score');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('sets');
        });
    }
};
