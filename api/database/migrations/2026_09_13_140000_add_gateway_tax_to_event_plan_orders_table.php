<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tax portion already inside `gateway_fee`, snapshotted so the invoice
     * can print it as its own line.
     *
     * It has to be stored rather than recomputed from `config/payment_fees.php`
     * at render time: the rate is a contract number that will change, and a
     * document that recalculates would quietly restate tax on every invoice
     * ever issued. Same reason `payment_method` and `platform_fee` are
     * snapshotted per order. Rows written before this column exists report 0,
     * which renders exactly as they did before — no tax line at all.
     */
    public function up(): void
    {
        Schema::table('event_plan_orders', function (Blueprint $table) {
            $table->decimal('gateway_tax', 12, 2)->default(0)->after('gateway_fee');
        });
    }

    public function down(): void
    {
        Schema::table('event_plan_orders', function (Blueprint $table) {
            $table->dropColumn('gateway_tax');
        });
    }
};
