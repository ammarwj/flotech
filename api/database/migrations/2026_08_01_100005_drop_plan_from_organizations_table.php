<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organization no longer has a plan. Events do.
 *
 * Runs last of the per-event billing migrations on purpose: `events:backfill-plan`
 * and everything before it must be able to run against a database that still has
 * these columns.
 *
 * `plan_expires_at` leaves without a reader. Nothing ever consulted it — there
 * was no expiry sweep and no downgrade — so a lapsed plan kept every entitlement
 * indefinitely. Per-event purchase removes the whole class of bug rather than
 * fixing it: there is no period left to expire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn('plan_expires_at');
        });
    }

    /**
     * Restores the columns but not their contents — the mapping from an
     * organization to "its" plan stopped existing the moment events started
     * carrying their own. Rolling this back for real means restoring the dump
     * taken before the deploy.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignUuid('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->timestamp('plan_expires_at')->nullable();
        });
    }
};
