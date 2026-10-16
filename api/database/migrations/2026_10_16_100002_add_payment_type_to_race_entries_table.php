<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fourth rail's copy of the instrument column — see
 * 2026_10_16_100000_add_payment_type_to_orders for what it is and why it does
 * not replace `payment_channel`.
 *
 * Separate from that migration rather than folded into it because that one has
 * already run: editing a migration that is recorded as Ran leaves every
 * database that applied it without the new column, and reports success.
 *
 * Fun run entries landed on a branch of their own, so they arrived after the
 * three rails were wired. The gap was not only this column: the `RUN-` arm had
 * been dropped from the webhook's `match` entirely, which is the silent failure
 * that controller's docblock warns about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('race_entries', function (Blueprint $table) {
            // Null is a real value: a manual transfer that never went through
            // Midtrans, a free (sponsored) entry, or anything settled before
            // this column existed. Indexed because grouping on it is the point.
            $table->string('payment_type', 32)->nullable()->after('payment_channel')->index();
        });
    }

    public function down(): void
    {
        Schema::table('race_entries', function (Blueprint $table) {
            $table->dropColumn('payment_type');
        });
    }
};
