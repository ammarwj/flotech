<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The free tier is gone: the entry plan is now Basic at Rp 49.000/bulan.
 *
 * Renamed in place rather than seeded as a new row. PlanSeeder matches on slug,
 * so a fresh 'basic' row would leave the old 'free' row behind — still active,
 * still public, and still the plan every existing organization points at.
 * Keeping the same id also keeps organizations and subscriptions attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')->where('slug', 'free')->update([
            'name' => 'Basic',
            'slug' => 'basic',
            'price_monthly' => 49000,
            'price_yearly' => 470000, // Plan::computeYearlyPrice(49000, 20)
            'yearly_discount_percent' => 20,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('plans')->where('slug', 'basic')->update([
            'name' => 'Free',
            'slug' => 'free',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'yearly_discount_percent' => 0,
            'updated_at' => now(),
        ]);
    }
};
