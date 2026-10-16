<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Midtrans instrument an order was actually settled with.
 *
 * NOT a rename of `payment_channel`, which already sits next to it: that one is
 * the *fee group* the buyer picked **before** paying (`va|ewallet|retail`, see
 * config/payment_fees.php) and is what `gateway_fee`/`gateway_tax` were computed
 * from. This one is only known **after** settlement, and one channel covers
 * several of them — `ewallet` carries gopay, shopeepay and qris alike. Two
 * questions, two columns: overwriting either leaves the fee already charged
 * impossible to reconcile, with no error anywhere.
 *
 * `event_plan_orders` is deliberately absent — it has carried this column since
 * 2026_07_13_100005.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_orders', function (Blueprint $table) {
            // Null is a real value here, and three different things read as it:
            // a manual/onsite order that never went through Midtrans, a free
            // order, and any gateway order settled before this migration.
            // Indexed because the whole point of the column is `GROUP BY` on it.
            $table->string('payment_type', 32)->nullable()->after('payment_channel')->index();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->string('payment_type', 32)->nullable()->after('payment_channel')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_orders', function (Blueprint $table) {
            $table->dropColumn('payment_type');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('payment_type');
        });
    }
};
