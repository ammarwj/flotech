<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One issued certificate. The recipient's name is snapshotted here rather than
 * read through the relation: a team can be renamed or a player deleted, but an
 * issued document must keep saying what it said when it was signed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            // Keep the certificate if the template is deleted — the PDF is already rendered.
            $table->foreignUuid('certificate_template_id')->nullable()
                ->constrained('certificate_templates')->nullOnDelete();

            $table->string('certificate_number')->unique();

            $table->string('recipient_type', 10); // team|player
            $table->uuid('recipient_id')->nullable();
            $table->string('recipient_name');
            $table->string('team_name')->nullable();  // the player's team; null for team certs
            $table->string('recipient_email')->nullable(); // team manager's — players have no email
            $table->string('award_title');

            $table->text('pdf_key')->nullable(); // object key in R2
            $table->timestamp('issued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'recipient_type']);
            // One award per recipient per event — re-running a batch must not
            // hand the same team a second "Juara 1".
            $table->unique(['event_id', 'recipient_type', 'recipient_id', 'award_title'], 'certificates_recipient_award_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
