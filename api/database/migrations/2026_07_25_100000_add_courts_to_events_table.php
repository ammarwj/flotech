<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named courts/pitches an event runs on. When set, scheduling picks a court by
 * name from a dropdown instead of typing free text, and the auto-generator
 * labels its lanes with these names instead of a generic "Lapangan 1..N".
 *
 * Null = no list defined; every existing event keeps the old free-text venue
 * behaviour, so nothing that already runs changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->json('courts')->nullable()->after('location_address');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('courts');
        });
    }
};
