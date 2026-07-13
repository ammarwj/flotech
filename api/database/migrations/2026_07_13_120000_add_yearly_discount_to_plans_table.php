<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('yearly_discount_percent', 5, 2)->default(0)->after('price_yearly');
        });

        // Existing rows already carry a discount implicitly (yearly is priced below
        // 12x monthly); recover it so the column matches what is actually billed.
        Plan::query()->where('price_monthly', '>', 0)->each(function (Plan $plan) {
            $full = (float) $plan->price_monthly * 12;
            $discount = (1 - (float) $plan->price_yearly / $full) * 100;

            $plan->forceFill(['yearly_discount_percent' => round($discount, 2)])->save();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('yearly_discount_percent');
        });
    }
};
