<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a certificate's template-placed fields printed, frozen onto the row.
 *
 * A certificate is a document: its PDF is rendered once at issue time, parked
 * in the bucket, and quoted by number forever. `recipient_name` and `team_name`
 * are already snapshotted onto the row for exactly that reason — a player who
 * later corrects their name does not retroactively change the award they were
 * handed. The registration answers a template can now place (a school, a club,
 * a jersey size) are the same kind of fact and get the same treatment.
 *
 * Resolving them at render time instead was the alternative, and it fails in a
 * way nothing would catch: re-rendering a 2026 certificate after the organizer
 * edited the 2027 form prints a document that disagrees with the PDF already in
 * somebody's inbox, with the same number on it. The ID card side does NOT need
 * this column, and the difference is deliberate — a reprint there is supposed
 * to pick up today's data (see IdCardService::issue()), because what the
 * snapshot protects is the card already in a hand and nothing reprints that.
 *
 * Nullable with no backfill: null on every row issued before templates could
 * place these fields is the correct state, and the renderer draws nothing for a
 * key it has no value for — the same as an unanswered optional field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->json('field_values')->nullable()->after('team_name');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn('field_values');
        });
    }
};
