<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * There is no subscription any more, so the table stops claiming there is.
 *
 * What a row means now: one purchase of one plan, which becomes a *credit* the
 * organization holds until an event spends it. `event_id` is that ledger, and
 * it is UNIQUE — "one order, one event" has to be a database fact rather than a
 * convention two controllers agree on.
 *
 * The link lives here rather than as `plan_order_id` on `events` because this is
 * the side that must be unique: the question the database has to answer is "has
 * this credit been spent". `events.plan_id` stays a plain snapshot that outlives
 * the order row, exactly like the `platform_fee` and `payment_method` snapshots
 * elsewhere.
 *
 * `billing_cycle`, `starts_at` and `expires_at` go: a one-time purchase has no
 * period. Nothing read the two timestamps except the billing PDF, which now
 * prints the event the credit was spent on instead.
 *
 * Status `active` becomes `paid`. "Active" described a period that no longer
 * exists; `paid` is the word `ticket_orders.status` and `teams.payment_status`
 * already use, and HasManualPayment::settledValue() exists precisely so the
 * three can name their own settled value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('subscriptions', 'event_plan_orders');

        Schema::table('event_plan_orders', function (Blueprint $table) {
            $table->foreignUuid('event_id')->nullable()->after('plan_id')
                ->constrained('events')->nullOnDelete();
            $table->unique('event_id');
            $table->timestamp('consumed_at')->nullable()->after('event_id');
        });

        Schema::table('event_plan_orders', function (Blueprint $table) {
            $table->dropColumn(['billing_cycle', 'starts_at', 'expires_at']);
        });

        // Safe without a collision guard: the old status set was
        // active|past_due|cancelled|expired, so nothing is already 'paid'.
        DB::table('event_plan_orders')->where('status', 'active')->update(['status' => 'paid']);

        // The column default was 'active', which is no longer a value this table
        // recognises. Nothing relies on it — EventPlanOrderService always writes
        // a status — but a default that contradicts the status set is a trap for
        // the next writer. A brand new order is awaiting payment.
        Schema::table('event_plan_orders', function (Blueprint $table) {
            $table->string('status', 20)->default('past_due')->change();
        });
    }

    public function down(): void
    {
        Schema::table('event_plan_orders', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->change();
        });

        DB::table('event_plan_orders')->where('status', 'paid')->update(['status' => 'active']);

        Schema::table('event_plan_orders', function (Blueprint $table) {
            $table->string('billing_cycle', 10)->default('monthly');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
        });

        Schema::table('event_plan_orders', function (Blueprint $table) {
            $table->dropUnique(['event_id']);
            $table->dropConstrainedForeignId('event_id');
            $table->dropColumn('consumed_at');
        });

        // Renamed back last: the statements above address the table by its new
        // name. The index `subscriptions_payment_method_status_index` kept its
        // original name through the rename, so neither direction touches it.
        Schema::rename('event_plan_orders', 'subscriptions');
    }
};
