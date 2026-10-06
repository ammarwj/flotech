<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual point adjustments an organizer applies to a category's table — events
 * with house rules the fixtures cannot express ("suporter datang lengkap = +2
 * poin, sebagian = +1"), or a sanction docking a squad that walked off.
 *
 * Without this the only way to say it is to type a fake scoreline, which lands
 * in `goals_for` and `set_diff` and every tiebreaker hanging off them.
 *
 * A ledger of several rows per team, not one total per team, and shaped like
 * `wallet_transactions`: a magnitude with a direction, a free-text reason, and
 * the actor who typed it. That is the house form for a human-entered number
 * that moves a total.
 *
 * Three things here are easy to misread later:
 *
 * - **`smallInteger`, not `unsignedSmallInteger`.** Unsigned is this schema's
 *   default (see create_matches_table) and would make -3 unstorable. One signed
 *   column means a bonus and a sanction are one mechanism, so there is no
 *   second rule to keep in step with the first.
 * - **`reason` is NOT NULL**, where `wallet_transactions.description` is
 *   nullable: a credit from a Midtrans webhook explains itself, this number
 *   does not. An unexplained ±2 on a public table is worse than no adjustment
 *   at all, which is why the column cannot be skipped.
 * - **No unique index.** Several rows per team summing to one figure *is* the
 *   design, not duplicates waiting to be cleaned up.
 *
 * Deleting a row is the only correction: there is no update endpoint, because
 * editing a signed number in place would leave the original author's name on a
 * statement they never made. So this is a ledger, not an audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standing_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // `category_id`, not `event_category_id` — the name this whole
            // schema already uses for this foreign key (teams, matches).
            $table->foreignUuid('category_id')->constrained('event_categories')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            // Signed: +2 for a bonus, -3 for a sanction.
            $table->smallInteger('points');
            $table->string('reason', 255);
            // Losing the account must not lose the entry it typed — the row is
            // still the reason a table reads the way it does.
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standing_adjustments');
    }
};
