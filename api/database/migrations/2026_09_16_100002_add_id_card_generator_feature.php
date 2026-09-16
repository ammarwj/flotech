<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sell the ID card generator on Pro and Professional.
 *
 * Carries its own data rather than calling PlanSeeder, the precedent set by
 * seed_per_event_plan_catalogue: deploy.sh runs `migrate --force` on every
 * release but only seeds under SEED=1, precisely so a super admin's edits at
 * /admin/plans survive a deploy. Without this migration the key would exist in
 * code and in a fresh install, and nowhere on the live database — every
 * template save would 403 with nothing in the pricing card to explain it.
 *
 * Additive only. It touches two feature keys' worth of rows and prunes nothing,
 * so a plan whose features a super admin has since retuned keeps every other
 * value exactly as they left it.
 */
return new class extends Migration
{
    /**
     * Both plans, not just Pro.
     *
     * Professional is here because Pro is: PlanGate::planCovers() requires an
     * upgrade target to grant at least every feature the current plan grants,
     * so a Professional missing this key would make Pro → Professional — the
     * plainest upgrade in the catalogue — fail the monotonicity test.
     */
    private const PLANS = ['pro', 'professional'];

    public function up(): void
    {
        $now = now();

        // Definition first, then values: the reverse order leaves a window in
        // which a plan carries a key the pricing card cannot render, and the
        // row would be invisible rather than struck through.
        if (! DB::table('feature_definitions')->where('feature_key', 'id_card_generator')->exists()) {
            DB::table('feature_definitions')->insert([
                'id' => (string) Str::uuid(),
                'feature_key' => 'id_card_generator',
                'feature_label' => 'Generator ID card',
                // Shares the certificate group on purpose — a group of one
                // renders as a stray heading on the pricing card.
                'feature_group' => 'certificate',
                'feature_type' => 'boolean',
                'description' => 'Cetak kartu identitas pemain, wasit, dan staf dari template.',
                'sort_order' => 140,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::PLANS as $slug) {
            $planId = DB::table('plans')->where('slug', $slug)->value('id');

            if (! $planId) {
                continue;
            }

            $exists = DB::table('plan_features')
                ->where('plan_id', $planId)
                ->where('feature_key', 'id_card_generator')
                ->exists();

            if ($exists) {
                // Already answered — possibly with a deliberate 'false' typed at
                // /admin/plans. Overwriting that would re-grant a feature a
                // super admin had switched off.
                continue;
            }

            DB::table('plan_features')->insert([
                'id' => (string) Str::uuid(),
                'plan_id' => $planId,
                'feature_key' => 'id_card_generator',
                'value' => 'true',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Values before the definition they point at, same order as
        // remove_platform_fee_percent_feature.
        DB::table('plan_features')->where('feature_key', 'id_card_generator')->delete();
        DB::table('feature_definitions')->where('feature_key', 'id_card_generator')->delete();
    }
};
