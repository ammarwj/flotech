<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // Penalty shootout, for knockout ties that end level. Null when the
            // match was decided in normal time (or can't go to penalties at all).
            $table->unsignedTinyInteger('home_penalty')->nullable()->after('away_score');
            $table->unsignedTinyInteger('away_penalty')->nullable()->after('home_penalty');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['home_penalty', 'away_penalty']);
        });
    }
};
