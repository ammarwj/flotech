<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The team sheet a manager hands in before kick-off, and the referee signs off.
 *
 * Three tables rather than one, and the split is the same invariant CLAUDE.md
 * already states for `team_officials`: **an official is not a player**. One table
 * with a nullable `player_id` / `team_official_id` pair is exactly how that
 * invariant leaks back — every reader would then have to remember which column is
 * filled, and the one that forgets puts a coach on the pitch with a shirt number.
 *
 * `match_lineups` is the submission itself: one row per team per fixture, which
 * is what `unique(['match_id','team_id'])` says. A tie has two of them and they
 * are reviewed independently — the home manager being late is not a reason the
 * away sheet cannot be approved.
 *
 * Two different actors are recorded, from two different tables, and that is not
 * an oversight: `submitted_by` is a `users` row (the manager holds an ordinary
 * participant account, `teams.manager_user_id`) while `reviewed_by` is an
 * `event_personnel` row (a referee's authority is the assignment to *this event*,
 * not their account). Both `nullOnDelete` — losing the person must not lose the
 * sheet they signed.
 *
 * Nothing here records who actually took the field. This is a pre-kickoff
 * submission; a player named on it may never leave the bench. See the note in
 * DisciplineService, which deliberately does not read these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_lineups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            // draft|submitted|approved|rejected. Editing is locked from
            // `submitted` onwards — that lock is the whole of what approval means.
            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('event_personnel')->nullOnDelete();
            // Why the referee sent it back. A rejection without one leaves the
            // manager guessing at what to change.
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['match_id', 'team_id']);
        });

        Schema::create('match_lineup_players', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lineup_id')->constrained('match_lineups')->cascadeOnDelete();
            $table->foreignUuid('player_id')->constrained('players')->cascadeOnDelete();
            // starter|substitute. The sheet prints them under separate headings,
            // so this is the one thing about a named player that is not already on
            // their roster row.
            $table->string('role', 20);
            // The order the manager typed, same as a bench: a squad list has no
            // natural order once shirt numbers are allowed to repeat across teams.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['lineup_id', 'player_id']);
        });

        Schema::create('match_lineup_officials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lineup_id')->constrained('match_lineups')->cascadeOnDelete();
            $table->foreignUuid('team_official_id')->constrained('team_officials')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['lineup_id', 'team_official_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_lineup_officials');
        Schema::dropIfExists('match_lineup_players');
        Schema::dropIfExists('match_lineups');
    }
};
