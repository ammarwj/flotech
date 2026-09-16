<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Answers to the extra fields an organizer defined for team_official_fields
     * — same shape and same reasoning as teams.custom_fields /
     * players.custom_fields.
     */
    public function up(): void
    {
        Schema::table('team_officials', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('photo_url');
        });
    }

    public function down(): void
    {
        Schema::table('team_officials', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });
    }
};
