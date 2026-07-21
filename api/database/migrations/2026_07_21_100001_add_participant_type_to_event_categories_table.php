<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A category declares what a single entrant *is*: one player, a pair, or a
 * squad. Badminton categories are already named this way in the real world
 * ("Tunggal Putra", "Ganda Campuran"), so the category is where it belongs.
 *
 * `rubber_format` is the template of individual matches (partai) a squad-vs-squad
 * tie is played over — [{"label":"Ganda Putra","type":"double"}, …]. Only a
 * squad category on a racket sport uses it; see EventCategory::usesRubbers().
 *
 * Defaulting to 'team' means every category that already exists keeps behaving
 * exactly as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_categories', function (Blueprint $table) {
            $table->string('participant_type', 10)->default('team')->after('slug'); // single|double|team
            $table->json('rubber_format')->nullable()->after('bracket_config');
        });
    }

    public function down(): void
    {
        Schema::table('event_categories', function (Blueprint $table) {
            $table->dropColumn(['participant_type', 'rubber_format']);
        });
    }
};
