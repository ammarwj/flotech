<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who meets who in the first knockout round, decided in *slots* ("Juara Grup A"
 * v "Runner-up Grup B") long before the teams filling them are known.
 *
 * Deliberately its own column rather than a key inside `bracket_config`: that
 * one is a format setting, round-tripped by the event form through a whitelist
 * (web/lib/hybrid.ts::hybridConfig), which would silently drop anything it does
 * not know about. A drawn bracket is an artefact of the draw — the same family
 * as `teams.group_name`, not as `groups`/`tiebreakers`.
 *
 * Null means "seed the bracket automatically from the standings", i.e. exactly
 * what every existing category does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_categories', function (Blueprint $table) {
            $table->json('knockout_plan')->nullable()->after('rubber_format');
        });
    }

    public function down(): void
    {
        Schema::table('event_categories', function (Blueprint $table) {
            $table->dropColumn('knockout_plan');
        });
    }
};
