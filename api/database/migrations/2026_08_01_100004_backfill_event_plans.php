<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Run the backfill as part of the deploy, so no window exists where events have
 * no plan and PlanGate is already reading `events.plan_id`.
 *
 * The work lives in `events:backfill-plan` rather than inline here for the same
 * reason `wallet:backfill` and `matches:backfill-group-stage` are commands: it
 * has to be re-runnable by hand against a database that got part-way, and a
 * migration can only ever run once.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nothing to do on a fresh install — the events table is empty and the
        // catalogue seeder has not run yet, so `professional` may not exist.
        if (DB::table('events')->whereNull('plan_id')->doesntExist()) {
            return;
        }

        Artisan::call('events:backfill-plan');
    }

    /**
     * Leaves `events.plan_id` populated. The column itself is dropped by
     * 100001's down(), which is the only rollback that means anything here.
     */
    public function down(): void
    {
        // no-op
    }
};
