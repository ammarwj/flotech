<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two file columns on the row an e-BIB is printed from.
 *
 * This is the whole of what the e-BIB feature stores, and the shortness is the
 * decision: `race_entries` is already the durable row a bib belongs to. It
 * carries the number printed on the sheet (`bib_number`), the credential its QR
 * encodes (`qr_code`, which RaceScanController scans at the race-pack desk) and
 * the address it is emailed to (`email`, a column of its own rather than a team
 * manager's borrowed account). A `bibs` table would be a second row per runner
 * that can disagree with this one, plus a second document number beside the one
 * already here — two spellings of one fact. See create_bib_templates_table.
 *
 * Both nullable with no backfill, and that is the correct production state: a
 * race whose organizer never printed bibs has nothing to point at. Precedent is
 * `tickets.event_date`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('race_entries', function (Blueprint $table) {
            /*
             * The rendered sheet, one object per runner, under
             * BibService::PRINT_PREFIX (`bib-prints/`). That prefix is
             * permanent, unlike BibService::BATCH_PREFIX which `bibs:prune`
             * sweeps on the download TTL: this column names the object, the
             * runner downloads it from their own proof page, and the email
             * attachment reads it.
             *
             * `text`, not `string`: a bucket key is unbounded in principle and
             * the column is never indexed or compared, matching
             * `id_cards.png_key` and `certificates.pdf_key`.
             */
            $table->text('bib_png_key')->nullable()->after('bib_number');

            /*
             * When the sheet was emailed to this runner. Stamped only after the
             * mail is handed to the transport — see SendBibsJob for why that
             * ordering is load-bearing. Null means never sent, which includes
             * every bib an organizer only ever printed.
             */
            $table->timestamp('bib_sent_at')->nullable()->after('bib_png_key');
        });
    }

    public function down(): void
    {
        Schema::table('race_entries', function (Blueprint $table) {
            $table->dropColumn(['bib_png_key', 'bib_sent_at']);
        });
    }
};
