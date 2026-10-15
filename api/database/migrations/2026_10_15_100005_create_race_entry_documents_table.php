<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files a runner uploads with their entry — a student card for the Mahasiswa
 * category, a health clearance.
 *
 * **Its own table, and `registration_documents` was genuinely considered.**
 * That table already carries three scopes (`team_id`, `player_id`,
 * `official_id`), so a fourth looks like the obvious move — the same
 * "reuse the columns" reasoning that made one `bank_accounts` table serve both
 * bank accounts and e-wallets. Two things refuse it, and either alone would:
 *
 * - `registration_documents.team_id` is **NOT NULL**. `player_id` and
 *   `official_id` are not a precedent for a fourth nullable scope, because a
 *   player's document still has a team; a runner has none. Reusing it means
 *   dropping that constraint for every row in the table.
 * - `MediaCleanupService::teamUrls()` sweeps those rows **by `team_id`**
 *   (app/Services/MediaCleanupService.php:150). An entry document with a null
 *   team is invisible to it, so deleting a fun run event would leave every
 *   uploaded file in R2 forever — no error, no symptom, just a bucket that
 *   grows. The fix would be a second reader of "which files belong to this
 *   event" inside the sweeper, which is the thing one table was supposed to
 *   avoid.
 *
 * So: same five columns, one FK, and `raceEntryUrls()` beside `teamUrls()` in
 * the sweeper, which `eventUrls()` calls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_entry_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('race_entry_id')->constrained()->cascadeOnDelete();
            // The key from this event's registration_form schema. Nullable for
            // the same reason the column it copies is: a file can be attached
            // before the schema names a slot for it.
            $table->string('document_type', 100)->nullable();
            $table->text('file_url');
            $table->string('file_name')->nullable();
            $table->bigInteger('file_size_bytes')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_entry_documents');
    }
};
