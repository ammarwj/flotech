<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ledger of every time a super admin forced `events.status` straight past
 * `Event::TRANSITIONS` — the escape hatch for `ongoing` having no way back to
 * `open`/`registration_closed` through the normal state machine (an organizer
 * who fat-fingers "Berlangsung" has no self-service undo; see
 * EventController::transition()/updateStatus(), which this does not touch).
 *
 * Deliberately its own table rather than a log tacked onto `events`: the
 * column it bypasses is the whole reason this exists, and `events` already
 * has no audit trail for any of its writes — adding one column for this one
 * action would be a trail that only ever has at most one entry worth reading.
 * A ledger of rows (pattern: `standing_adjustments`) answers "who force-set
 * this event's status, and why" in full, the same way `wallet_transactions`
 * answers "where did this balance come from" instead of a single `note` field
 * on `wallets`.
 *
 * `reason` is NOT NULL, same call as `standing_adjustments.reason`: forcing a
 * status outside the state machine with nothing explaining it is worse than
 * not having the row, because the next person to look is asking "why is this
 * event's status wrong" and the ledger exists to answer exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_status_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            // What it was and what it was forced to — both snapshotted, not
            // read back off `events`, because the row has to keep saying what
            // happened even after a later override moves the event again.
            $table->string('from_status', 30);
            $table->string('to_status', 30);

            $table->text('reason');

            // Losing the account must not lose the entry it typed — the row is
            // still the reason the event's status reads the way it does.
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_status_overrides');
    }
};
