<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whose document this is. Null means the team's (a mandate letter, a club
     * deed); set means that player's (a KTP belongs to a person, not a squad).
     *
     * `team_id` stays non-null either way, and that is load-bearing rather than
     * redundant: MediaCleanupService::teamUrls() sweeps this table by `team_id`,
     * so player documents are picked up by the existing cleanup — and by the
     * cascade behind it — without that service learning anything about players.
     */
    public function up(): void
    {
        Schema::table('registration_documents', function (Blueprint $table) {
            $table->foreignUuid('player_id')->nullable()->after('team_id')
                ->constrained('players')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registration_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('player_id');
        });
    }
};
