<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The days a ticket category sells, one row per date.
 *
 * **The rows ARE the catalogue.** The organizer's pick is not a json array on
 * the category with a separate counter beside it: that would be two readers of
 * "which days are valid", and the gap between them looks like a date a buyer
 * can pick whose seats are never counted. Same shape, same reason as
 * `plan_features`.
 *
 * **`sold` here answers a different question from `ticket_categories.sold`.**
 * This one is seats taken *on that date* — the venue's capacity, which is what
 * an organizer means by a quota. The category's own `sold` stays the total of
 * paid units, and is read only to decide whether a category may be deleted and
 * for report figures. Two counters answering two questions, never one question
 * twice: a `pass` order bumps every date here (a pass holder occupies a seat
 * each day) while bumping the category's `sold` by one.
 *
 * `quota` nullable = inherit `ticket_categories.quota`, the same "null means
 * inherit the catalogue" shape as `landing_stat_settings`. The unique index is
 * what keeps a date from being sold twice over by two rows nobody notices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_category_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_category_id')->constrained('ticket_categories')->cascadeOnDelete();
            $table->date('event_date');
            // null = inherit the category's quota; a number overrides it for
            // this date alone (a final day with a smaller stand).
            $table->integer('quota')->nullable();
            $table->integer('sold')->default(0);
            $table->timestamps();

            $table->unique(['ticket_category_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_category_days');
    }
};
