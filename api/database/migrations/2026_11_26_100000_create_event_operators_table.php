<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An event operator: someone an organizer trusted with part of ONE event's
 * day-to-day running, without handing them a seat in `organization_members`.
 *
 * Four boolean columns, not a JSON array and not a permission pivot, because
 * the set of categories is FIXED and known to the middleware by name —
 * `EnsureOperatorPermission` has to be able to say `event.perm.ticketing` in a
 * route file, the same reason `EventPersonnel::KINDS` is a hardcoded array
 * rather than a catalogue like `feature_definitions`. No `role` column either:
 * the four booleans together already answer "who is this person allowed to
 * be" — a role column would be a second fact that can drift from them (the
 * `effective_payment_method` lesson).
 *
 * Scoped to one event, not the organization: `event_id` is NOT NULL and there
 * is no org-level row. Two events of the same organizer may hand different
 * people different slices, same invariant as plans being bought per event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_operators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('can_ticketing')->default(false);
            $table->boolean('can_scheduling')->default(false);
            $table->boolean('can_registration')->default(false);
            $table->boolean('can_documents')->default(false);
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_operators');
    }
};
