<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedup ledger, not analytics data. Its only job is to make "have we already
 * counted this visitor on this event today?" an atomic question, which the
 * unique index below answers via INSERT ... ON CONFLICT DO NOTHING — the same
 * idempotency trick wallet_transactions uses for re-delivered webhooks.
 *
 * `visitor_hash` is an HMAC over IP + user agent + the day (see
 * EventViewService::visitorHash()); no raw IP is ever stored, and because the
 * date is part of the input, hashes for the same person on different days do
 * not correlate. Rows lose all meaning once their day is over and are dropped
 * by `views:prune`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_view_visitors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->date('viewed_on');
            $table->char('visitor_hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['event_id', 'viewed_on', 'visitor_hash']);
            $table->index('viewed_on'); // pruning
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_view_visitors');
    }
};
