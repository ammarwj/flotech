<?php

use App\Services\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The eleventh sport, and the first one that holds no matches at all.
 *
 * A fun run is entered by one runner who picks a category (Mahasiswa, Umum),
 * pays, and shows the receipt at the start line. There is no fixture, no table,
 * no bracket and no scoreline — which is what `scoring: 'none'` says. One
 * column, so it cannot disagree with itself: a separate `is_competitive`
 * boolean would be a second reader of a question `scoring` already answers,
 * and the two would drift exactly the way two readers of `stage` did.
 *
 * Seeded here rather than appended to SportSeeder for the reason
 * create_sport_official_roles_table and add_medic_admin_official_roles already
 * give: the seeder carries the entry for fresh databases, but running it
 * against production would overwrite every edit an admin has made to the ten
 * other sports' columns.
 *
 * `participant_modes: ['single']` is load-bearing and is one row of data doing
 * the work of a code branch: `usesSquadFields()` and `showsPlayerStats()` in
 * web/lib/scoring.ts are both keyed on whether a sport fields lone players, so
 * shirt numbers, positions and the statistics tab switch themselves off with
 * nothing added to either gate.
 *
 * No stats, no positions, no official roles, and all three config columns null:
 * null means "this sport has none at all", which is the same convention
 * SportResource already publishes for a set sport's period_config. The three
 * rulebooks (DisciplineRules, MatchClockRules, SquadRules) answer "is this on?"
 * from the catalog, never from these columns.
 *
 * Idempotent. It never touches the row if it is already there — an admin who
 * renamed or deactivated it keeps that decision.
 */
return new class extends Migration
{
    private const SLUG = 'fun_run';

    public function up(): void
    {
        if (DB::table('sports')->where('slug', self::SLUG)->exists()) {
            return;
        }

        $now = now();

        // Last in the list rather than wedged among the ball sports: the order
        // is what the landing page and every sport dropdown render, and a
        // non-competitive entry in the middle of them reads as a mistake.
        $order = (int) DB::table('sports')->max('sort_order') + 1;

        DB::table('sports')->insert([
            'id' => (string) Str::uuid(),
            'slug' => self::SLUG,
            'name' => 'Fun Run',
            'color' => '#F97316',
            'icon' => '🏃',
            'scoring' => 'none',
            'participant_modes' => json_encode(['single']),
            // All three null: no cards to accumulate, no clock to run, no
            // starting lineup to name. See the class docblock.
            'discipline_config' => null,
            'period_config' => null,
            'squad_config' => null,
            // Nothing is played, so there is no duration to default. 0 rather
            // than a plausible-looking number nobody reads: ScheduleService is
            // never reached for this sport, and a 90 sitting here would invite
            // the next reader to wonder what it schedules.
            'default_match_minutes' => 0,
            'is_active' => true,
            'sort_order' => $order,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Catalog::flush();
    }

    /**
     * Only an unused sport goes back, the same rule SportController::destroy
     * enforces: an event already held in it would lose the slug its rows name.
     */
    public function down(): void
    {
        if (DB::table('events')->where('sport_type', self::SLUG)->exists()) {
            return;
        }

        DB::table('sports')->where('slug', self::SLUG)->delete();

        Catalog::flush();
    }
};
