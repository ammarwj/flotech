<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Stored and matched uppercase (DiscountCode::normalize). A code
            // typed in lower case must not fail to match the row an admin
            // created, and two rows differing only in case would be two codes
            // nobody can tell apart.
            $table->string('code', 40)->unique();
            // Shown to the admin only — the organizer sees the amount, not why.
            $table->string('description')->nullable();
            // percent|fixed. The arithmetic for both lives in
            // DiscountCode::discountFor(); nothing else computes a discount.
            $table->string('discount_type', 10);
            $table->decimal('discount_value', 12, 2);
            // Ceiling for percent codes. Null = uncapped, and it is ignored for
            // fixed codes, where `discount_value` already *is* the ceiling.
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            // Null on either side = open-ended on that side.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // Null = no quota. There is deliberately no redeemed counter here:
            // usage is derived from event_plan_orders (see DiscountCodeService),
            // so there is no second writer that could drift out of step with the
            // orders it is supposed to be counting.
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->boolean('once_per_organization')->default(true);
            $table->timestamps();
        });

        // Which plans a code applies to. NO rows = every plan — the code is
        // unrestricted, not useless. DiscountCodeService enforces that reading.
        Schema::create('discount_code_plan', function (Blueprint $table) {
            $table->foreignUuid('discount_code_id')->constrained('discount_codes')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->unique(['discount_code_id', 'plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_code_plan');
        Schema::dropIfExists('discount_codes');
    }
};
