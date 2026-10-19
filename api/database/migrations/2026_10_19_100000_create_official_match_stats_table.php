<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cards shown to a team's bench — the coach sent off, the manager cautioned.
 *
 * A second table rather than a nullable `player_id` / `team_official_id` pair on
 * `player_match_stats`, and the reason is the invariant CLAUDE.md already states
 * for `team_officials` and `match_lineup_*`: **an official is not a player.** One
 * table with two nullable owner columns is precisely how that invariant leaks
 * back — every reader then has to remember which column is filled, and the one
 * that forgets puts a coach on the leaderboard with a shirt number.
 *
 * Keeping them apart means the five readers of `player_match_stats` that have no
 * business with the bench — PlayerStatService::leaderboard(),
 * MatchStatService::assistError(), MatchReportService's squad tables,
 * PublicEventController::matchStatLine() and the goals-vs-score cross-check —
 * are *structurally* unable to see this feature. None of them was touched.
 *
 * Only card stats ever land here. A coach does not score: `stat_key` is checked
 * against the sport's `yellow`/`red` roles at the write door, not against the
 * whole catalogue, so a gol column for the bench cannot be written even by a
 * caller that offers one.
 *
 * Shape is otherwise a copy of `player_match_stats`, deliberately: it is an
 * aggregate per fixture per person per key (`unique`), the write path is a full
 * replace, and suspensions are derived from it on every read rather than stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_match_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('match_id')->constrained('matches')->cascadeOnDelete();
            // The side they sat with when the card was shown, snapshotted the
            // same way player_match_stats.team_id is: team_officials.team_id can
            // change afterwards, and the card belongs to the fixture.
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('team_official_id')->constrained('team_officials')->cascadeOnDelete();
            $table->string('stat_key', 30);
            $table->unsignedSmallInteger('value')->default(0);
            $table->timestamps();

            $table->unique(['match_id', 'team_official_id', 'stat_key']);
            $table->index(['match_id', 'stat_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_match_stats');
    }
};
