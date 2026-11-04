<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A runner asks for their entry fee back; the organizer rules on it.
 *
 * > **Invarian: "sedang diajukan" DITURUNKAN dari kolom-kolom ini, bukan nilai
 * > keenam di `status`.** This is the same rule HasManualPayment already
 * > enforces for "awaiting verification", and for the same reason: three
 * > services guard on `in_array($entry->status, ['paid','cancelled','refunded'])`
 * > (RaceEntryService::cancel(), RefundService::refundRaceEntry(),
 * > race-entries:expire-manual), and a new value slipped into that column walks
 * > silently past every one of them — a pending request would stop the hourly
 * > sweep from expiring an abandoned entry, and `refundRaceEntry()` would refuse
 * > the very row it exists to refund. `status` keeps meaning what the money did;
 * > these columns mean what somebody has asked for. Test it by COMPARING an
 * > entry mid-request with a plain paid one: `status` must read `paid` on both.
 *
 * The refund decision itself still lands in `status` as `refunded`, written by
 * RefundService — the one thing allowed to reverse a wallet credit. What is new
 * here is only the conversation that leads to it.
 *
 * **`refund_amount` is deliberately absent.** A refund is all of `price` and
 * nothing less: a partial one needs `WalletService` to reverse part of a credit,
 * which `wallet:audit` reconstructs from whole rows, and the organizer's answer
 * was that they would rather refuse a request than haggle over it in software.
 *
 * Nullable in every column and null in every row is the correct production
 * state, not a pending backfill — the precedent is `tickets.event_date`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('race_entries', function (Blueprint $table) {
            // What the runner asked and when. `refund_requested_at` is the
            // single column every reader tests for "is there a request here":
            // the reason may be short but it is never empty (the form demands
            // it), and a timestamp is what the organizer's queue sorts on.
            $table->text('refund_reason')->nullable();
            $table->timestamp('refund_requested_at')->nullable();

            // The verdict. `refund_decided_at` set with `refund_approved_at`
            // still null is a refusal — two columns rather than a boolean
            // because "not decided" and "decided no" are different states and a
            // nullable bool spells the first one two ways.
            $table->timestamp('refund_decided_at')->nullable();
            $table->foreignUuid('refund_decided_by')->nullable()->constrained('users')->nullOnDelete();
            // Why it was turned down, shown to the runner on their own page.
            // Null on an approval, which needs no explanation.
            $table->text('refund_rejected_reason')->nullable();

            // The organizer's queue: this event's open requests, oldest first.
            $table->index(['event_id', 'refund_requested_at']);
        });
    }

    public function down(): void
    {
        Schema::table('race_entries', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'refund_requested_at']);
            $table->dropConstrainedForeignId('refund_decided_by');
            $table->dropColumn([
                'refund_reason',
                'refund_requested_at',
                'refund_decided_at',
                'refund_rejected_reason',
            ]);
        });
    }
};
