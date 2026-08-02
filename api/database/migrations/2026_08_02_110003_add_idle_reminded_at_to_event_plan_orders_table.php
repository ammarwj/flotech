<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the owner was last nudged that this credit is still sitting unspent.
 *
 * Stored rather than derived: the reminder is the only record that it was ever
 * sent, and without it a daily sweep would mail the same organizer every single
 * day for as long as they held the credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_plan_orders', function (Blueprint $table) {
            $table->timestamp('idle_reminded_at')->nullable()->after('consumed_at');
        });
    }

    public function down(): void
    {
        Schema::table('event_plan_orders', function (Blueprint $table) {
            $table->dropColumn('idle_reminded_at');
        });
    }
};
