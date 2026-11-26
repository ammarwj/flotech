<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invite to become an event operator — addressed to an EMAIL, not a user.
 *
 * Kept as its own table rather than a nullable `token`/`email`/`status` set of
 * columns on `event_operators`, because that would be exactly the
 * nullable-pair shape this codebase keeps rejecting (see `teams`/`race_entries`
 * discount snapshots, `bank_accounts` account_type, etc.): a row would carry
 * two meanings — "this invite is outstanding" and "this person operates this
 * event" — and nothing would force them to agree. A row in `event_operators`
 * only ever exists AFTER `accept()` runs.
 *
 * `event_operator_invites_pending_unique` (next migration, raw SQL) is the
 * real enforcement of "one pending invite per (event, email)" — this table
 * alone cannot express a partial unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_operator_invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('email');
            $table->boolean('can_ticketing')->default(false);
            $table->boolean('can_scheduling')->default(false);
            $table->boolean('can_registration')->default(false);
            $table->boolean('can_documents')->default(false);
            $table->string('status', 20)->default('pending'); // pending|accepted|revoked|expired
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('invited_by')->constrained('users');
            $table->foreignUuid('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['event_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_operator_invites');
    }
};
