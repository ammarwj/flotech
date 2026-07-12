<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // Seeding pot (1..n) used by the pot-based group draw. Null = unseeded.
            $table->unsignedTinyInteger('seed_pot')->nullable()->after('group_name');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('seed_pot');
        });
    }
};
