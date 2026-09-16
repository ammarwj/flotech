<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whose document this is, third case: null player_id + set official_id
     * means it belongs to a bench entry (a coach's KTP), not the team or a
     * player. team_id stays non-null here too — see the comment on player_id.
     */
    public function up(): void
    {
        Schema::table('registration_documents', function (Blueprint $table) {
            $table->foreignUuid('official_id')->nullable()->after('player_id')
                ->constrained('team_officials')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registration_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('official_id');
        });
    }
};
