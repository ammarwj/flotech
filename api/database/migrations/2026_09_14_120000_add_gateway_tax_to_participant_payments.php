<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tax portion already inside `gateway_fee`, for the two participant
     * payments — same column event_plan_orders already carries.
     *
     * Without it the documents could only print one combined "Biaya
     * pembayaran" line, because splitting the tax out would have meant printing
     * a number nothing in the row backed. PaymentFeeCalculator has always
     * returned it; it was simply being dropped on the way in.
     *
     * Snapshotted rather than recomputed at render time for the same reason as
     * everywhere else: the rate is a contract number that will change, and a
     * document that recalculates would quietly restate the tax on every receipt
     * ever issued.
     *
     * Rows written before this column exists report 0, and the tax line is
     * skipped for them — exactly how they render today.
     */
    public function up(): void
    {
        foreach (['ticket_orders', 'teams'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->decimal('gateway_tax', 12, 2)->default(0)->after('gateway_fee');
            });
        }
    }

    public function down(): void
    {
        foreach (['ticket_orders', 'teams'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('gateway_tax');
            });
        }
    }
};
