<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An e-BIB template: the organizer's own artwork plus the coordinates of the
 * fields printed on top of it. Shaped on `id_card_templates`, including why it
 * belongs to the organization rather than to an event — one design is reused
 * across seasons, which is what makes the template an org-level entitlement
 * while printing a particular race's bibs stays event-keyed.
 *
 * Its own table rather than a `kind` discriminator on `id_card_templates`, and
 * the renderer being shareable is not the question. The field catalogues have
 * almost nothing in common: a bib carries a large race number, a category and
 * a check-in QR, and carries no photo, no `role_label` and no `team_name` at
 * all. One table with two catalogues means IdCardTemplateRules and the editor
 * branch on `kind` in every rule they state.
 *
 * A5 landscape by default, not CR80: a bib is pinned to a chest and read from
 * across a finish line, and the number on it has to be legible at that
 * distance. Still two numbers rather than an orientation enum, for the reason
 * `id_card_templates` gives — the presets offered in the UI never reach the API.
 *
 * > **There is deliberately no `bibs` table, and unlike `id_cards` that absence
 * > is permanent rather than deferred.** `id_cards` had to exist because
 * > players and officials own no numbered row of their own: a card used to be a
 * > pure function of (person, template), so a QR on one had nothing to point
 * > at. A runner is not in that position. `race_entries` is already the durable
 * > row, and it already carries the number (`bib_number`), the credential
 * > (`qr_code`, which RaceScanController scans at the desk) and the address
 * > (`race_entries.email`, a column of its own rather than a team manager's
 * > borrowed account). A second table would mean two rows per runner that can
 * > disagree, plus a second document number beside the `bib_number` that
 * > already exists — two spellings of one fact. What `race_entries` gains
 * > instead is two file columns; see add_bib_columns_to_race_entries_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bib_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->text('background_url');

            // A5 landscape — the common race-bib size, and the reason the
            // default differs from the card twin's CR80.
            $table->decimal('width_mm', 6, 2)->default(210.00);
            $table->decimal('height_mm', 6, 2)->default(148.00);

            /*
             * Two coordinate conventions live in here, exactly as they do in
             * `id_card_templates`. See config/bib.php for the full shapes; the
             * short version:
             *
             *   text fields — x/y is the point the text is ALIGNED TO, and
             *                 `size` is MILLIMETRES, so "the number is 60mm
             *                 tall" survives a DPI change and can be checked
             *                 with a ruler.
             *   the QR      — x/y is the TOP-LEFT CORNER of its box, because a
             *                 box resized from a corner must be anchored at
             *                 one. Carries w/h (percent) instead of `size`.
             *
             * x/y/w/h are percentages, so a layout survives both a DPI change
             * and a change of bib size.
             */
            $table->json('fields');

            $table->timestamps();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bib_templates');
    }
};
