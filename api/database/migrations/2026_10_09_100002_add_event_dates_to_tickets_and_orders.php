<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which day each issued ticket is for, and what the order actually bought.
 *
 * **`quantity` keeps meaning "paid units".** That is what makes
 * `total_price = unit_price * quantity`, `increment('sold', $quantity)`, the
 * `decrement` in refund()/cancel(), `units` in PaymentFeeCalculator and the
 * wallet ledger description all stay untouched. The two columns here carry what
 * is left over:
 *
 * | mode      | seats | event_dates | quantity | ticket rows |
 * |-----------|-------|-------------|----------|-------------|
 * | `none`    | 2     | null        | 2        | 2 (date null) |
 * | `per_day` | 2     | 14,15,16    | **6**    | 6 |
 * | `pass`    | 2     | 14,15,16    | **2**    | 6 |
 *
 * `pass` is the one place `quantity` is not the row count, and that is its
 * definition: paid once, admitted each day. Deriving `quantity` from
 * `count(event_dates)` would bill a pass holder three times.
 *
 * `tickets.event_date` is **nullable**, and that null is load-bearing: it is
 * what a `none` category issues, and it is what every ticket already in the
 * database has. The scanner skips its date comparison entirely when it is null,
 * so there is no second branch keeping old tickets alive.
 *
 * `event_dates` is a **snapshot**, like `payment_method` and `platform_fee`
 * beside it: a category may change which dates it sells, and an order taken
 * before that must keep naming the days it was sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->date('event_date')->nullable()->after('event_id');

            $table->index(['event_id', 'event_date']);
        });

        Schema::table('ticket_orders', function (Blueprint $table) {
            // People, not paid units. Default 1 is only right for a brand-new
            // row; the backfill below fixes the orders that already exist.
            $table->integer('seats')->default(1)->after('quantity');
            $table->json('event_dates')->nullable()->after('seats');
        });

        // Every existing order is a `none` one, where seats and paid units are
        // the same number. Idempotent: running it again writes the same values.
        DB::table('ticket_orders')->update(['seats' => DB::raw('quantity')]);
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'event_date']);
            $table->dropColumn('event_date');
        });

        Schema::table('ticket_orders', function (Blueprint $table) {
            $table->dropColumn(['seats', 'event_dates']);
        });
    }
};
