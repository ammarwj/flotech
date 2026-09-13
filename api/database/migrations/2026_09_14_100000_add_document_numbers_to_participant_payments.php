<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoice & receipt numbers for the two payments a participant makes:
     * buying tickets and paying a registration fee.
     *
     * Same shape as the plan-order columns added in
     * 2026_07_13_100005_add_billing_to_subscriptions_table: nullable, and
     * unique as the last line of defence behind DocumentNumberService's lock.
     *
     * Deliberately **not** backfilled, unlike that migration. A plan order that
     * predates its numbering still represents money we hold; these rows do not
     * get re-settled, so issuing numbers for them would be back-dating
     * documents for payments nobody was given one for. Null stays null, and the
     * download endpoints 404 on it.
     */
    public function up(): void
    {
        foreach (['ticket_orders', 'teams'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('invoice_number', 30)->nullable()->unique();
                $t->string('receipt_number', 30)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        foreach (['ticket_orders', 'teams'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['invoice_number', 'receipt_number']);
            });
        }
    }
};
