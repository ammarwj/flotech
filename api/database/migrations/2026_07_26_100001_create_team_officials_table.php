<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people on a team's bench — pelatih, manajer, ofisial — kept apart from
 * `players` on purpose.
 *
 * A coach is not a player with a different label, and putting them in the same
 * table would break five things that read that roster for reasons of their own:
 * TeamRosterService::assertRosterSize() (a doubles entry is exactly two rows),
 * syncDerivedName() (a doubles entry *is* its players' names — "Dimas / Ammar"),
 * RubberController::validateLineup() (the partai lineup picker offers every
 * Player of the team), player_match_stats / the leaderboard, and
 * CertificateService::recipients(). None of them has to know this table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_officials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('full_name');
            // A key from sport_official_roles; null when the sport defines none.
            $table->string('role', 30)->nullable();
            $table->text('photo_url')->nullable();
            // Players have a natural order (shirt number); a bench does not, so
            // the order they were typed in is the order they are shown.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_officials');
    }
};
