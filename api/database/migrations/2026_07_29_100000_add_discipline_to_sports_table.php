<?php

use App\Services\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What turns a card into a ban: how many yellows accumulate before a player
 * misses a match, and how long a red keeps them out.
 *
 * Two things are needed, and they are two because they answer different
 * questions. `sport_stats.role` says *which* stat is a yellow and which is a
 * red — the suspension engine may not go looking for a stat_key literal, since
 * an admin is free to rename keys from /admin/sports. `sports.discipline_config`
 * holds the sport's default thresholds, which an event may then override.
 *
 * `fair_play_weight` deliberately stays out of this. It is a tiebreaker weight
 * an admin is entitled to tune (2 and 5 are as valid as 1 and 3); hanging
 * suspension semantics on it would mean editing the standings tiebreaker
 * silently changes who is allowed to play.
 */
return new class extends Migration
{
    /**
     * The stat keys this platform shipped with, and what they mean. Only rows
     * whose role is still null are touched — an admin who has already labelled
     * something keeps their answer.
     *
     * @var array<string, string>
     */
    private const ROLE_BACKFILL = [
        'yellow_cards' => 'yellow',
        'red_cards' => 'red',
    ];

    /**
     * Three yellows across the tournament sit a player down for one match, two
     * in a single match are a sending-off, and a red does it on its own.
     *
     * Kept in step with DisciplineRules::DEFAULTS by hand rather than imported:
     * a migration is a record of what the column held on the day it ran, and
     * pointing it at a constant that later changes would rewrite history.
     */
    private const DEFAULTS = [
        'yellow_threshold' => 3,
        'yellow_ban_matches' => 1,
        'red_ban_matches' => 1,
        'yellows_per_expulsion' => 2,
        'expulsion_ban_matches' => 1,
        'reset_yellow_on_knockout' => false,
    ];

    public function up(): void
    {
        Schema::table('sports', function (Blueprint $table) {
            $table->json('discipline_config')->nullable()->after('participant_modes');
        });

        foreach (self::ROLE_BACKFILL as $statKey => $role) {
            DB::table('sport_stats')
                ->where('stat_key', $statKey)
                ->whereNull('role')
                ->update(['role' => $role]);
        }

        // Only sports that actually book players get a rulebook. A sport with no
        // card stat has nothing to threshold, and giving it a config would make
        // the feature claim to be enabled where it can never fire.
        $carded = DB::table('sport_stats')
            ->whereIn('role', ['yellow', 'red'])
            ->distinct()
            ->pluck('sport_id');

        if ($carded->isNotEmpty()) {
            DB::table('sports')
                ->whereIn('id', $carded)
                ->update(['discipline_config' => json_encode(self::DEFAULTS)]);
        }

        // The catalog is remembered forever; without this the new roles and
        // config stay invisible until some admin write happens to flush it.
        Catalog::flush();
    }

    public function down(): void
    {
        Schema::table('sports', function (Blueprint $table) {
            $table->dropColumn('discipline_config');
        });

        DB::table('sport_stats')
            ->whereIn('stat_key', array_keys(self::ROLE_BACKFILL))
            ->whereIn('role', self::ROLE_BACKFILL)
            ->update(['role' => null]);

        Catalog::flush();
    }
};
