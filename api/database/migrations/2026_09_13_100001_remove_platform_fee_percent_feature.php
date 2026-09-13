<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Order matters: values before the catalog entry they point to.
        DB::table('plan_features')->where('feature_key', 'platform_fee_percent')->delete();
        DB::table('feature_definitions')->where('feature_key', 'platform_fee_percent')->delete();
    }

    public function down(): void
    {
        // Data-removal migration — the old fee-percent values aren't worth resurrecting.
    }
};
