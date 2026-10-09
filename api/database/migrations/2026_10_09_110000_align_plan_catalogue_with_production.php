<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bring the seeded catalogue in line with the one actually being sold.
 *
 * Production's catalogue was trimmed by hand at /admin/plans and has since
 * diverged from PlanSeeder in two ways. Both were deliberate product decisions,
 * and the code disagreeing with them is the bug:
 *
 *  - `starter` is no longer sold.
 *  - Pro does not include online registration, the ID card generator, sponsor
 *    logos, or the organizer profile. Those four are what separates it from
 *    Professional.
 *
 * Carries its own data rather than calling PlanSeeder, the precedent set by
 * seed_per_event_plan_catalogue: deploy.sh runs `migrate --force` on every
 * release but seeds only under SEED=1, precisely so a super admin's edits
 * survive a deploy. Which also means production is already in this state and
 * every statement here is a no-op there — what this migration is really for is
 * fresh installs and developer databases, where PlanSeeder still writes the old
 * tiering and `online_registration` on Pro silently re-opens the public
 * registration form.
 *
 * The seeder is edited to match in the same change. This migration exists
 * because editing it is not enough on its own: an existing database is never
 * re-seeded.
 */
return new class extends Migration
{
    /**
     * What Pro does not include.
     *
     * Rows are deleted rather than written as `'false'`, matching PlanSeeder's
     * own docblock: PlanGate reads a missing value and an explicit 'false'
     * identically, and the pricing card strikes both through, so a 'false' row
     * only adds a row that means what its absence already meant.
     *
     * Professional keeps all four — PlanGate::planCovers() requires an upgrade
     * target to grant at least everything the current plan does, and Pro is a
     * subset of it in every key, so Pro → Professional stays monotone.
     */
    private const PRO_EXCLUDES = [
        'online_registration',
        'id_card_generator',
        'sponsor_logos',
        'organizer_profile',
    ];

    public function up(): void
    {
        $proId = DB::table('plans')->where('slug', 'pro')->value('id');

        if ($proId !== null) {
            DB::table('plan_features')
                ->where('plan_id', $proId)
                ->whereIn('feature_key', self::PRO_EXCLUDES)
                ->delete();
        }

        // Deactivated, never deleted — the same reasoning that retired `basic`
        // in seed_per_event_plan_catalogue: historical orders point at the row,
        // `plan_id` is nullOnDelete, and losing it would make old invoices read
        // "Paket dihapus".
        //
        // Its feature rows stay, unlike `basic`'s. Basic was the old free tier
        // and granted nothing anyone paid for; Starter was sold at Rp 150.000,
        // and an event still running on it is entitled to exactly what was
        // bought. `is_active`/`is_public` already keep it off every surface that
        // sells a plan, which is the whole of what "no longer sold" means —
        // stripping the features would retroactively take an entitlement away
        // from a tournament that may still be mid-season.
        $starterId = DB::table('plans')->where('slug', 'starter')->value('id');

        if ($starterId !== null) {
            DB::table('plans')->where('id', $starterId)->update([
                'is_active' => false,
                'is_public' => false,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Irreversible by design, like the catalogue migration before it.
     *
     * Down would have to re-grant features to Pro and put Starter back on sale —
     * that is, undo a pricing decision rather than a schema change. Nothing here
     * destroys information that cannot be re-typed at /admin/plans, which is
     * where the decision was made in the first place.
     */
    public function down(): void {}
};
