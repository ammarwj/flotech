<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_plan_orders', function (Blueprint $table) {
            // The FK is for the usage ledger (how many times was this code
            // redeemed?), and it goes null when the code row is deleted. The
            // four columns beside it are a *snapshot*: an issued invoice must
            // keep reading what was actually charged even after a super admin
            // edits or deletes the code. Same reason withdrawals snapshot the
            // destination account and every order snapshots `payment_method`.
            $table->foreignUuid('discount_code_id')->nullable()->after('plan_id')
                ->constrained('discount_codes')->nullOnDelete();
            $table->string('discount_code', 40)->nullable()->after('discount_code_id');
            $table->string('discount_type', 10)->nullable()->after('discount_code');
            $table->decimal('discount_value', 12, 2)->nullable()->after('discount_type');
            // What was taken off the catalogue price. `amount` is already net of
            // it, so this is what the PDF prints as a line and what the plan
            // price is reconstructed from (amount + discount_amount).
            $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('event_plan_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_code_id');
            $table->dropColumn(['discount_code', 'discount_type', 'discount_value', 'discount_amount']);
        });
    }
};
