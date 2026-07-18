<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The zone the venue is in. Kickoff times are stored UTC, but "15:00" as
     * typed by the organizer means 15:00 *there* — without this column the app
     * can only ever be right for one zone (it was WIB, and hardcoded at that).
     *
     * Defaults to Asia/Jakarta so existing events keep the behaviour they were
     * created under.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('timezone', 64)->default('Asia/Jakarta')->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
