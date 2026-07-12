<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Matches events.sport_type — the slug is the stored value.
            $table->string('slug', 30)->unique();
            $table->string('name');
            $table->string('color', 20)->default('#1E6FFF');
            $table->string('icon', 8)->nullable(); // emoji
            // goal = one running score · set = scored per set (volley, racket sports)
            $table->string('scoring', 10)->default('goal');
            $table->unsignedSmallInteger('default_match_minutes')->default(60);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('sport_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sport_id')->constrained('sports')->cascadeOnDelete();
            $table->string('stat_key', 30);
            $table->string('label');
            $table->string('short', 6);
            // What the stat means to the engine: 'goal' cross-checks the score,
            // 'assist' can't outnumber the goals. Null = plain counter.
            $table->string('role', 10)->nullable();
            // Disciplinary weight for the fair-play tiebreaker (yellow 1, red 3).
            $table->unsignedTinyInteger('fair_play_weight')->default(0);
            // Lowest sort_order is the primary stat the leaderboard ranks by.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['sport_id', 'stat_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sport_stats');
        Schema::dropIfExists('sports');
    }
};
