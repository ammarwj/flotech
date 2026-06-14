<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // Registration fee payment. 'paid' covers free events (amount 0).
            $table->string('payment_status', 20)->default('paid')->after('status'); // unpaid|paid
            $table->decimal('payment_amount', 12, 2)->default(0)->after('payment_status');
            $table->decimal('platform_fee', 12, 2)->default(0)->after('payment_amount');
            $table->string('midtrans_order_id', 100)->nullable()->unique()->after('platform_fee');
            $table->text('midtrans_token')->nullable()->after('midtrans_order_id');
            $table->timestamp('paid_at')->nullable()->after('midtrans_token');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'payment_status',
                'payment_amount',
                'platform_fee',
                'midtrans_order_id',
                'midtrans_token',
                'paid_at',
            ]);
        });
    }
};
