<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a cancelled event stood before it was cancelled.
 *
 * A snapshot, like `payment_method` on an order: cancelling costs nothing that
 * cannot be given back, so the event can be reactivated — but only to the
 * status it genuinely held, which is what keeps a published event from ever
 * landing back in `draft` (a draft can be deleted, and deleting it returns the
 * plan credit).
 *
 * Rows cancelled before this column existed stay null on purpose;
 * Event::RESTORE_FALLBACK answers for them, so there is no second rule to keep
 * in step with a backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('status_before_cancel', 30)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('status_before_cancel');
        });
    }
};
