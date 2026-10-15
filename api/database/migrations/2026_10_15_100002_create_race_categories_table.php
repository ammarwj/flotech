<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The categories a fun run sells entries in: Mahasiswa, Umum, 5K, 10K.
 *
 * **Its own table, not `event_categories`.** That one is read by `matches`,
 * `teams`, `standing_adjustments`, StandingService, ScheduleService,
 * BracketSeeding, SwissService, KnockoutPlanService and EventCategoryResource
 * (`uses_rubbers`, `standings_context`, `roster_size`, `engine`). Widening it
 * would force `tournament_format` nullable and grow one branch in nine readers
 * to answer a question only this flow asks — the same reason
 * BillingDocumentService was kept apart from ParticipantDocumentService.
 * `ticket_categories` already proved the independent path: FK `event_id` only,
 * zero link to `event_categories`, and nothing in the ticket path has ever had
 * to know a competition category exists.
 *
 * **No `price` column, and that absence is the design.** What an entry costs
 * depends on when it is bought, so the prices live in `race_category_prices`,
 * one row per (category, period). A column here would be a second reader of
 * "what does this cost" and the two would answer differently the first time an
 * organizer edits a period.
 *
 * `taken` mirrors `ticket_categories.sold`: the quota reservation, incremented
 * when an entry is held and decremented when one is cancelled, so an abandoned
 * checkout really does hold a place for as long as it has to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 100);
            $table->text('description')->nullable();
            // null = unlimited, the convention ticket_categories.quota uses.
            $table->integer('quota')->nullable();
            $table->integer('taken')->default(0);
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_categories');
    }
};
