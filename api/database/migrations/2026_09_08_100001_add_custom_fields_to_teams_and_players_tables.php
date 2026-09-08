<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The answers to the extra fields an organizer defined in
     * `events.registration_form` — a team's address, a player's ID number.
     *
     * Answers, not schema: the schema lives once on the event, while these are
     * inherently per row. Deliberately not EAV — the answer set is small, it is
     * always read alongside the row it belongs to, and the participant export
     * needs it flat anyway.
     *
     * Nullable, so every existing team and player stays valid untouched.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('contact_phone');
        });

        Schema::table('players', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('photo_url');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });

        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });
    }
};
