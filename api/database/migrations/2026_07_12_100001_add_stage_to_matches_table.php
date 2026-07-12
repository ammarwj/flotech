<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // Hybrid events run two stages: group | knockout. Null for the
            // single-stage formats (league, knockout, double elimination).
            $table->string('stage', 16)->nullable()->after('event_id');
            // Leg number for double-leg (home & away) ties. Always 1 otherwise.
            $table->unsignedTinyInteger('leg')->default(1)->after('order');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['stage', 'leg']);
        });
    }
};
