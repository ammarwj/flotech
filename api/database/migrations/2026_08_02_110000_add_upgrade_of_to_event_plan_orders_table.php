<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a top-up bill to the order it upgrades.
 *
 * Set at checkout rather than at settlement: which order is being upgraded is
 * decided the moment the organizer asks, and the payment may not land for days.
 *
 * One column, not two. "This order has been upgraded" is derived from the
 * successor row (`whereDoesntHave('upgrade')` in scopeUnconsumed) instead of a
 * flag here, so there is no second copy of the fact to fall out of step with
 * this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_plan_orders', function (Blueprint $table) {
            $table->foreignUuid('upgrade_of_id')
                ->nullable()
                ->after('event_id')
                ->constrained('event_plan_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_plan_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('upgrade_of_id');
        });
    }
};
