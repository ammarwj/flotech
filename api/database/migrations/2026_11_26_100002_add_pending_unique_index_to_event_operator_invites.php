<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "One email + event gets at most one PENDING invite" is a database fact, not
 * an application-layer check — the same reasoning as
 * `event_operator_invites_pending_unique`'s siblings elsewhere in this
 * codebase (`event_plan_orders.event_id` unique, `plan_features` key
 * enforcement): a race between two "invite" clicks must not be able to leave
 * two rows a resend/revoke can disagree about.
 *
 * Partial index — Laravel's schema builder has no `->where()` for unique
 * indexes, hence raw SQL in its own migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX event_operator_invites_pending_unique ON event_operator_invites (event_id, email) WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS event_operator_invites_pending_unique');
    }
};
