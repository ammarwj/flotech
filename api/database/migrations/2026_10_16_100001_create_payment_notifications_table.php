<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every Midtrans HTTP notification, exactly as it arrived.
 *
 * An audit trail, **not** a source of money figures. Midtrans re-delivers
 * notifications, so one payment writes several rows here — there is deliberately
 * no unique index, the opposite of `wallet_transactions`, which is idempotent on
 * `(source_type, source_id, category)` precisely so a re-delivery cannot credit
 * twice. Anything counting revenue must therefore sum the order tables
 * (ticket_orders / teams / event_plan_orders), never these rows.
 *
 * Rows are written **before** the signature is checked, so a rejected payload
 * leaves a trace too — the first question when an order is unpaid though the
 * buyer paid is usually "did a notification even arrive, and was it accepted?",
 * and today the answer only exists in a log file. A rejected row still moves
 * nothing: handle() returns 403 exactly as before.
 *
 * Nothing in here is ever updated except `handled`/`source_*`, stamped by the
 * arm that found the order, hence the single timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // What ties the row to an order. Not a foreign key: a notification
            // can arrive for an order id nothing points at any more —
            // EventPlanOrderService::pay() mints a fresh Midtrans order id for
            // the same bill and abandons the old one, whose late webhook is
            // simply a 404. An FK would reject the very row that explains it.
            $table->string('midtrans_order_id', 64)->index();

            // Which table the order was found in, mirroring
            // `wallet_transactions.source_type` (2026_07_13_100002). A string
            // rather than morphs() because null carries meaning here: the order
            // was not found at all.
            $table->string('source_type', 40)->nullable();
            $table->uuid('source_id')->nullable();

            // Midtrans' own id for the payment — the only key that finds it
            // again in their dashboard.
            $table->string('transaction_id', 64)->nullable()->index();
            $table->string('transaction_status', 32)->nullable();

            // The reason this table exists: qris | gopay | bank_transfer | …
            $table->string('payment_type', 32)->nullable()->index();
            $table->string('fraud_status', 32)->nullable();
            $table->string('status_code', 8)->nullable();

            // What Midtrans *says* it charged, so it can be compared against the
            // order's own gross_amount instead of trusted.
            $table->decimal('gross_amount', 14, 2)->nullable();
            // Midtrans' clock, not ours.
            $table->timestamp('transaction_time')->nullable();

            // False = the payload was rejected and moved nothing.
            $table->boolean('signature_valid')->default(false);
            // False = no order matched (the 404 arms). Without this the only
            // trace of "Midtrans settled something we do not recognise" is a log
            // line nobody greps.
            $table->boolean('handled')->default(false);

            // The full response, for the columns above that Midtrans may grow.
            $table->json('payload');

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notifications');
    }
};
