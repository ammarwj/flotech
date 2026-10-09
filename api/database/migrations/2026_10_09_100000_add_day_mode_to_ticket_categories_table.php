<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a ticket category speaks about *days* at all.
 *
 * A tournament running 14–16 Nov sells access per day: a buyer picks the days
 * they attend, each day costs one ticket price, and the gate must turn away a
 * ticket meant for another day. Until now a ticket knew nothing about days —
 * `sale_start`/`sale_end` is the *sale* window, not the attendance day.
 *
 * **One column, three modes — not two booleans.** `none|per_day|pass`:
 *
 * - `none` — today's behaviour, exactly. No day rows, tickets carry a null
 *   `event_date`, and the scanner compares nothing. That is what the default
 *   buys: every category that already exists keeps working untouched, with no
 *   backfill and no second branch anywhere.
 * - `per_day` — the buyer picks days and pays per day.
 * - `pass` — one price, every day the category sells.
 *
 * `is_daily` + `is_pass` would have a fourth state that means nothing and has
 * to be kept impossible by a second rule. One column cannot disagree with
 * itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->string('day_mode', 10)->default('none')->after('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->dropColumn('day_mode');
        });
    }
};
