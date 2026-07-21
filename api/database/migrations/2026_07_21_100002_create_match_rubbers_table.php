<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The individual matches (partai) a squad-vs-squad tie is played over.
 *
 * A badminton tie is not one scoreline: "Spanyol 3-0 Argentina" is the count of
 * partai won, each of which has its own lineup and its own set scores. So the
 * parent match keeps home_score/away_score (= rubbers won) and `matches.sets`
 * stays null — a single run of sets is meaningless for a tie.
 *
 * Lineups are player ids from the two teams' rosters rather than free text, so
 * they can be reconciled with player_match_stats and certificates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_rubbers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('match_id')->constrained('matches')->cascadeOnDelete();
            $table->unsignedSmallInteger('order')->default(0);
            $table->string('label');           // "Ganda Putra"
            $table->string('type', 10);        // single | double
            $table->json('home_player_ids')->nullable();
            $table->json('away_player_ids')->nullable();
            // Per-set scores, same shape as matches.sets: [{"home":21,"away":16}, …]
            $table->json('sets')->nullable();
            // Sets won by each side — derived from `sets`, stored so the tie can
            // be rolled up without re-reading every set (same trade the parent
            // match already makes).
            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();
            $table->string('status', 20)->default('scheduled'); // scheduled|finished|walkover
            $table->timestamps();

            $table->unique(['match_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_rubbers');
    }
};
