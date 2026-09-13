<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ticket_orders', 'teams'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                // va|ewallet|retail; null = manual transfer or a free order.
                $table->string('payment_channel', 20)->nullable()->after('payment_method');
                $table->decimal('gateway_fee', 12, 2)->default(0)->after('payment_channel');
                $table->decimal('service_fee', 12, 2)->default(0)->after('gateway_fee');
            });
        }
    }

    public function down(): void
    {
        foreach (['ticket_orders', 'teams'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['payment_channel', 'gateway_fee', 'service_fee']);
            });
        }
    }
};
