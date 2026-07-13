<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social profiles live in one JSON map ({instagram: url, ...}) instead of a
     * column per platform: the set of platforms is marketing-driven and adding
     * the next one shouldn't cost a migration.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('social_links')->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('social_links');
        });
    }
};
