<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_discount_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // The code belongs to ONE event, and that is the whole difference
            // from `discount_codes`: that table is plan-keyed and a super admin
            // owns it, this one is typed by the organizer running this event.
            // Same reasoning as the plan-belongs-to-an-event invariant.
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            // Stored and matched uppercase (HasDiscountFormula::normalize). A
            // code typed in lower case must not fail to match the row the
            // organizer created.
            $table->string('code', 40);
            // Shown to the organizer only — the participant sees the amount,
            // never why it came off.
            $table->string('description')->nullable();
            // percent|fixed. The arithmetic for both lives in
            // HasDiscountFormula::discountFor(); nothing else computes one.
            $table->string('discount_type', 10);
            $table->decimal('discount_value', 12, 2);
            // Ceiling for percent codes. Null = uncapped, and it is ignored for
            // fixed codes, where `discount_value` already *is* the ceiling.
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            // Null on either side = open-ended on that side.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // Null = no quota. There is deliberately no redeemed counter here:
            // usage is derived from `teams` + `race_entries` (see
            // EventDiscountCodeService), so there is no second writer that could
            // drift out of step with the registrations it is meant to count.
            $table->unsignedInteger('max_redemptions')->nullable();
            // Keyed on the participant account, not an email: both register
            // routes are behind `auth:api` and both rows record their owner
            // (`teams.manager_user_id`, `race_entries.user_id`), so one key
            // answers for both tables. `teams` holds a phone number, not an
            // email, so the email reading would need a join and would be a
            // second answer that could disagree.
            $table->boolean('once_per_user')->default(true);
            $table->timestamps();

            // Scoped unique, NOT global: the code is the event's, and two
            // organizers are both entitled to run an `EARLYBIRD`.
            $table->unique(['event_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_discount_codes');
    }
};
