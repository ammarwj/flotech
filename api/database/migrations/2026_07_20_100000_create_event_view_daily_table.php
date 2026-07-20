<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily roll-up of public event page traffic — one row per event per day.
 *
 * This is the only table any statistic is read from; the per-visitor ledger
 * next to it exists purely to answer "counted already today?" and is pruned.
 * Rows here are never deleted: 1000 events over three years is ~1M rows, and
 * the history *is* the product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_view_daily', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();

            // Denormalised from events. Admin asks "how much traffic does this
            // organizer get?" far more often than anything per-event, and an
            // event never changes hands, so the copy can't drift.
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // The day in config('wallet.timezone'), not UTC — see EventViewService::today().
            $table->date('viewed_on');

            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('unique_visitors')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'viewed_on']);   // upsert key
            $table->index(['organization_id', 'viewed_on']);
            $table->index('viewed_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_view_daily');
    }
};
