<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One runner's entry in a fun run: who they are, which category, what they
 * paid, and — later — the code scanned at the start line.
 *
 * **Its own table, not a `teams` row with one player.** The codebase does have
 * that precedent (tunggal badminton is a Team holding one Player, and
 * `teams.name` is derived from it), and it was weighed: it would have inherited
 * payments, invoices, manual proof, the dynamic registration form and the
 * participant dashboard for free. It is refused because the roster is zero rows
 * — there is nobody to derive a name from — and because `teams` carries two
 * status columns where one of them, the organizer admitting the squad, means
 * nothing here. The cost is five touch points (the webhook arm, the expire
 * command, the wallet rail, the refund arm, the verification queue) and they
 * are all written once.
 *
 * The payment columns mirror `ticket_orders` exactly, because HasManualPayment
 * needs the whole set and because a second shape of the same money would drift
 * from the first. What is deliberately NOT here is an `awaiting_verification`
 * status: that state is derived from the proof columns, which is the whole
 * reason that trait exists — slipping a new value into a status column walks
 * silently past every guard written as `in_array($status, [...])`.
 *
 * **The price is a SNAPSHOT, not a join.** `period_name` and `price` are copies,
 * and `race_period_id` is nullOnDelete, for the same reason `withdrawals`
 * snapshots the bank account and every order snapshots its `payment_method`:
 * organizers edit period prices mid-sale, and a receipt already issued must not
 * change what it says. Test it by COMPARING one entry's stored price before and
 * after its period's price is edited — it must be identical — and a second pair
 * where the period is deleted outright and `period_name`/`price` still read.
 * Asserting "saved at the early-bird price" alone would pass even if the price
 * were derived from the FK, because in a one-step test nothing has changed yet.
 *
 * **The last four columns are phase two, written now and read by nothing.**
 * QR check-in at the start line is deliberately deferred; laying the columns
 * down with it keeps that phase to code alone. Null in every row is the correct
 * production state, not a pending backfill — the precedent is
 * `tickets.event_date`, whose null is what lets every ticket predating per-day
 * ticketing keep working with no second branch at the gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete, not cascade: a category somebody has paid to
            // enter is not something to delete out from under them. The
            // controller refuses it with a message instead, the same way
            // TicketCategoryController refuses a category that has sold.
            $table->foreignUuid('race_category_id')->constrained()->restrictOnDelete();
            // The account that filed it. Nullable so an entry outlives a
            // deleted account — the receipt stays valid and the organizer's
            // participant list keeps the name, which is held below as text.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            // The runner, as they typed themselves. Held as text rather than
            // read off `users` for the reason ticket_orders holds buyer_name:
            // the person entering is not always the account holder, and a
            // profile edited next year must not rewrite who ran this race.
            $table->string('full_name');
            $table->string('email');
            $table->string('phone', 30)->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('birth_date')->nullable();
            // A race asks for this and a tournament does not: the organizer
            // needs somebody to call from the course, and it is the one field
            // here that exists for safety rather than for admin.
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            // Whatever else this event's registration form asks for, same
            // shape as `teams.custom_fields`.
            $table->json('custom_fields')->nullable();

            // Price snapshot — see the class docblock.
            $table->foreignUuid('race_period_id')->nullable()->constrained()->nullOnDelete();
            $table->string('period_name', 60);
            $table->decimal('price', 12, 2);

            // Money. `status` is the lifecycle column HasManualPayment reads;
            // pending | paid | cancelled | refunded, the same four values and
            // the same spelling as ticket_orders.
            $table->string('status', 20)->default('pending');
            $table->string('payment_method', 10)->default('gateway');
            $table->string('payment_channel', 20)->nullable();
            // Retired on the other rails and kept at 0 here for the same
            // reason: the buyer-paid gateway and service fees replaced it.
            $table->decimal('platform_fee', 12, 2)->default(0);
            $table->decimal('gateway_fee', 12, 2)->default(0);
            // Part of gateway_fee, not on top of it — never added into gross.
            $table->decimal('gateway_tax', 12, 2)->default(0);
            $table->decimal('service_fee', 12, 2)->default(0);
            // Null for a free entry: a Rp 0 invoice claims a transaction that
            // never happened. Unique as the last net under DocumentNumberService.
            $table->string('invoice_number', 30)->nullable()->unique();
            $table->string('receipt_number', 30)->nullable()->unique();
            $table->string('midtrans_order_id', 100)->nullable()->unique();
            $table->text('midtrans_token')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Manual transfer: the buyer uploads a receipt, an org admin rules
            // on it. Every column here is one HasManualPayment reads.
            $table->text('payment_proof_url')->nullable();
            $table->timestamp('payment_proof_uploaded_at')->nullable();
            $table->timestamp('payment_deadline_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            // Check-in. Nullable, and null is a real state: an unpaid entry
            // has no code because RaceEntryService::hold() must NOT mint one —
            // a credential issued before settlement admits somebody who has
            // not paid, and taking it back later means deciding what happens
            // to codes already handed out. That is exactly the decision
            // receipt_number avoids by being born only when the money arrives,
            // and markPaid() mints the QR the same way.
            $table->string('bib_number', 20)->nullable();
            $table->string('qr_code', 64)->nullable()->unique();
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignUuid('checked_in_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The organizer's participant list, and the report figures, are
            // always "this event, these statuses".
            $table->index(['event_id', 'status']);
            // What race-entries:expire-manual sweeps by, the same pair
            // teams and ticket_orders are indexed on.
            $table->index(['payment_method', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_entries');
    }
};
