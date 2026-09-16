<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An ID card template is the organizer's own artwork plus the coordinates of
 * the fields printed on top of it. Like certificate_templates it belongs to the
 * organization rather than to an event, so one design can be reused across
 * seasons — and that is exactly what makes it an org-level entitlement while
 * printing a particular event's cards stays event-keyed.
 *
 * Millimetres, not an orientation enum: the organizer picks the card size, and
 * the presets offered in the UI (CR80, A6, A7, Kustom) never reach the API as
 * an enum — picking one just writes two numbers.
 *
 * There is deliberately no issued-cards table. A certificate has a row because
 * it carries a number and a verification URL printed on the document itself; a
 * card carries neither, and nothing points at one. It is a pure function of
 * (person, template), both of which stay alive. What that costs, stated openly:
 * no trace of who was printed and when, no stable URL for a single card, and
 * editing a template silently ages every card already printed from it. Adding
 * `id_cards` later would be purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_card_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->text('background_url');

            // CR80 — the credit-card size every lanyard holder is cut for.
            $table->decimal('width_mm', 6, 2)->default(85.60);
            $table->decimal('height_mm', 6, 2)->default(54.00);

            /*
             * Two coordinate conventions live in here, and mixing them up is the
             * easiest mistake to make in the renderer. See config/id_card.php
             * for the full shapes; the short version:
             *
             *   text fields  — x/y is the point the text is ALIGNED TO, same as
             *                  certificate_templates. `size` is millimetres.
             *   the photo    — x/y is the TOP-LEFT CORNER of its box, because a
             *                  box resized from a corner must be anchored at
             *                  one. Carries w/h (percent) instead of `size`.
             *
             * x/y/w/h are percentages, so a layout survives both a DPI change
             * and a change of card size.
             */
            $table->json('fields');

            $table->timestamps();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_card_templates');
    }
};
