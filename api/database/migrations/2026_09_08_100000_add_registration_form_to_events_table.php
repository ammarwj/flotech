<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shape of this event's registration form: which documents entrants must
     * upload and which extra fields they must fill in.
     *
     * Its own column rather than a namespace inside `rules_config`, and the
     * reason is access rather than tidiness: `rules_config` is deliberately
     * published only by EventResource (organizer) and never by
     * PublicEventResource, while this schema *has* to reach the public
     * registration page — it is what that page renders itself from. Folding it
     * into `rules_config` would force us to expose a column that also holds the
     * discipline rulebook.
     *
     * Null means no documents and no fields are defined, which is what every
     * existing event gets: the form keeps behaving exactly as it does today.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->json('registration_form')->nullable()->after('rules_config');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('registration_form');
        });
    }
};
