<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which rail this event sells on: `gateway` (Midtrans) or `manual` (buyers
     * transfer to the organizer's own account and upload proof).
     *
     * Non-null with a default rather than nullable. "Follow the default" would
     * have to follow *something*, and there is no platform-wide default payment
     * method — the global switch is an override, not a default. So null and
     * 'gateway' would behave identically forever: a third state every future
     * reader has to answer for and none can tell apart (the `stage IS NULL`
     * class of bug). Defaulting to 'gateway' also reproduces today's behaviour
     * for every existing row without a backfill.
     *
     * No index: the three order tables index their copy of this column because
     * sweeps filter on it; nothing filters `events` by it.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('payment_method', 10)->default('gateway')->after('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
