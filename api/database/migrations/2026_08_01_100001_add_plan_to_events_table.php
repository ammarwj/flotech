<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The entitlement snapshot. From here on a plan belongs to an event, not to an
 * organization: two events of one organizer can legitimately answer differently
 * about tickets, certificates or the platform fee.
 *
 * Nullable, deliberately. Three reasons, and none of them is laziness:
 *  - the foreign key is nullOnDelete, so a super admin deleting a plan would
 *    otherwise violate the constraint it is supposed to satisfy;
 *  - PlanGate has to answer "no plan" as *zero entitlement* regardless, so NULL
 *    is a state the code must handle — a schema constraint would only hide it;
 *  - NOT NULL turns every seeder and every Event::create() in the test suite
 *    into a hard failure instead of a gate refusal.
 *
 * The invariant lives in EventController::store (which refuses to create an
 * event without a paid, unspent plan order) and in PlanGate (which grants
 * nothing without a plan) — not in this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignUuid('plan_id')->nullable()->after('organization_id')
                ->constrained('plans')->nullOnDelete();

            // Postgres does not index foreign keys on its own, and this is the
            // column "which events run on this plan" is answered by — the check
            // that stops Admin\PlanController::destroy() from stripping
            // entitlements off live tournaments.
            $table->index('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['plan_id']);
            $table->dropConstrainedForeignId('plan_id');
        });
    }
};
