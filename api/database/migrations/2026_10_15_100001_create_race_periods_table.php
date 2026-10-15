<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sale periods of one fun run event: Early Bird, Pre Sale, Last Call.
 *
 * **The rows ARE the catalogue** — same shape, same reason as
 * `ticket_category_days` and `plan_features`. A json array on `events` with the
 * prices beside it would be two readers of "which period is in force", and the
 * gap between them looks like a period a runner can register under whose price
 * is never charged.
 *
 * **The windows belong to the EVENT, the prices to the category.** One set of
 * dates, every category filling in what it costs during each of them (see
 * race_category_prices). That is the organizer's own mental model — "early bird
 * runs the first ten days" is an event-wide announcement — and it keeps the
 * dates typed once instead of once per category.
 *
 * `name` is free text, not an enum: three periods is a habit, not a rule, and an
 * organizer running two or five is doing nothing unusual.
 *
 * **`starts_at` and `ends_at` are NOT NULL**, unlike `ticket_categories.sale_start`
 * beside them. There a null bound means "no bound", which is a sensible answer
 * for a single window. Here the windows sit next to each other, so an open-ended
 * one swallows its neighbour: an Early Bird with no end stays in force through
 * Last Call, and RacePricing picks it forever without anything looking wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // Stored as UTC instants, like every other window in this schema.
            // The model forces it on write — see RacePeriod::startsAt(), which
            // copies TicketCategory::saleStart() for the same reason: a sale
            // opens on the venue's clock, and without it 00:00 WIB lands as
            // 17:00 the previous day and early bird ends seven hours early.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            // Read on every public registration page load to resolve "which
            // period is now", always by event.
            $table->index(['event_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_periods');
    }
};
