<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One issued ID card.
 *
 * The create_id_card_templates_table migration stated this table's absence as a
 * decision and said adding it later would be purely additive. This is that
 * addition, and the premise that justified the absence is exactly what changed:
 * a card now carries a number and a verification URL printed on it, so there is
 * something for a QR to point at. config/id_card.php said the same thing about
 * its missing `qr` field; that docblock is updated alongside this.
 *
 * Snapshotted rather than read through the relation, for the reason spelled out
 * on `certificates`: a player can be deleted and a team renamed, but a card
 * already hanging around someone's neck must keep saying what is printed on it.
 * `id_card_template_id` is nullOnDelete for the twin reason the certificate's
 * is — the PNG is already rendered.
 *
 * The unique index deliberately does NOT include `card_number`, unlike
 * certificates_recipient_award_unique. A certificate's key carries
 * `award_title` because one team legitimately receives both "Juara 1" and
 * "Fair Play"; a card has no second axis. One person gets one card per event,
 * and reprinting updates that row rather than minting a second number — a
 * second number would kill the QR on every card already handed out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('id_card_template_id')->nullable()
                ->constrained('id_card_templates')->nullOnDelete();

            $table->string('card_number')->unique();

            // player|official|personnel — the three pools IdCardService flattens.
            $table->string('recipient_type', 12);
            $table->uuid('recipient_id')->nullable();

            $table->string('recipient_name');
            $table->string('role_label')->nullable();
            $table->string('team_name')->nullable();

            /*
             * Where the card will be emailed. Snapshotted like the rest of the
             * row and for a sharper reason than the name: players and team
             * officials have no email column at all, so this is the team
             * manager's account address — the same single address
             * CertificateService::issueBatch() has to settle for. Event
             * personnel carry their own. Null means there is nobody to send to,
             * which is an ordinary state, not an error.
             */
            $table->string('recipient_email')->nullable();

            /*
             * The per-person PNG, kept alongside the batch zip rather than
             * instead of it. An email attachment and a one-card reprint both
             * want one object per person, and slicing it back out of a 400-card
             * zip means downloading 400 cards to send one.
             *
             * Its prefix (`id-card-prints/`) is permanent, unlike
             * IdCardService::BATCH_PREFIX which `id-cards:prune` sweeps hourly.
             * The row points at this object and its QR has been handed out.
             */
            $table->text('png_key')->nullable();

            $table->timestamp('issued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'recipient_type']);
            $table->unique(['event_id', 'recipient_type', 'recipient_id'], 'id_cards_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_cards');
    }
};
