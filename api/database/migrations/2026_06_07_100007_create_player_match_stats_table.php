<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replace the goals-only table with a generic per-sport stat store.
        Schema::dropIfExists('match_goals');

        Schema::create('player_match_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('player_id')->constrained('players')->cascadeOnDelete();
            // e.g. goals, assists, yellow_cards, points, aces, blocks…
            $table->string('stat_key', 30);
            $table->unsignedSmallInteger('value')->default(0);
            $table->timestamps();

            $table->unique(['match_id', 'player_id', 'stat_key']);
            $table->index(['match_id', 'stat_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_match_stats');
    }
};
