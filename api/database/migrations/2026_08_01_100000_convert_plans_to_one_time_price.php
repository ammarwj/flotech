<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan is bought once, for one event — there is no billing period left to
 * price, so the monthly rate becomes the only price and the yearly columns go.
 *
 * Renamed rather than replaced by a fresh column: `price_monthly` already holds
 * the number every existing row is priced at, and leaving it behind would be a
 * second source of truth the admin editor could still write to.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Two blueprints on purpose: Postgres does not reliably rename and drop
        // columns of the same table in one ALTER batch.
        Schema::table('plans', function (Blueprint $table) {
            $table->renameColumn('price_monthly', 'price');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['price_yearly', 'yearly_discount_percent']);
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->renameColumn('price', 'price_monthly');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('price_yearly', 12, 2)->default(0)->after('price_monthly');
            $table->decimal('yearly_discount_percent', 5, 2)->default(0)->after('price_yearly');
        });
    }
};
