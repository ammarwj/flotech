<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A runner moving their entry from one race category to another.
 *
 * The 5K entrant who meant to pick 10K, and the one who changed their mind.
 * Before this the only way out was a refund plus a fresh registration, which
 * loses the receipt number, the QR already on their phone, and — once the sale
 * has moved on — the price they bought at.
 *
 * Its own table rather than columns on `race_entries`, and the reason is the
 * same one that gave `event_plan_orders` its upgrade chain: a runner may move
 * twice (5K → 10K → 21K), and a per-row pair of columns can only hold the last
 * move. More importantly the difference owed has to be computed against
 * EVERYTHING already paid, which only a ledger of the moves can answer.
 *
 * > **Invariant: `race_entries.price` is NEVER rewritten, and this table is
 * > what answers "how much have they paid".** `ParticipantDocumentService`
 * > renders the invoice and the receipt on demand FROM THE ROW, so rewriting
 * > `price` silently changes a document somebody already downloaded — the
 * > snapshot rule already spelled out for `withdrawals`' bank account, for
 * > `period_name` beside `price`, and for every order's `payment_method`. So
 * > `RaceEntryTransfer::paidTowards()` sums the entry's own `price` plus every
 * > settled transfer's `amount`, the mirror of
 * > `EventPlanOrder::paidTowardsPlan()`. Reading `price` alone passes every
 * > one-step test and then overcharges the moment somebody moves twice: after
 * > 5K→10K the runner has paid 100k + 100k, so a jump to 21K priced off
 * > `price` alone bills them 100k again. Test it by COMPARING a 5K→10K→21K
 * > chain against a direct 21K entry — the totals must be EQUAL; asserting
 * > "the second transfer cost something" passes on both.
 *
 * > **Invariant: the target price is read from the ENTRY's period, never the
 * > current one.** A runner who bought in Early Bird moves at Early Bird
 * > prices, which is the organizer's own answer and also the only one that
 * > cannot be gamed by waiting. `race_period_id` is nullable (`nullOnDelete`),
 * > and a null period REFUSES rather than falling through to the open window:
 * > inventing a number nobody can point at an approval for is exactly what
 * > `RacePricing` refuses to do for a gap between windows. Test it by COMPARING
 * > a transfer made during period two against period one's price list;
 * > asserting "a price was stored" alone passes even when the current window is
 * > what got read.
 *
 * > **Invariant: a cheaper target refunds through `refund_payouts`, never by
 * > rewriting the credit.** The original `race_entry` credit stays exactly as
 * > written — `wallet:audit` reconstructs balances from whole rows, and editing
 * > one is how a ledger stops reconciling. What a downgrade writes is a debit
 * > for the difference plus one payout row, so the two-hop transfer queue and
 * > the runner's own panel serve it with nothing new to learn. Test it by
 * > COMPARING the wallet rows before and after: the credit must be byte-identical
 * > and the debit must equal the difference.
 *
 * `amount` is the DIFFERENCE, always ≥ 0, with `direction` giving it a sign —
 * the same shape `wallet_transactions` uses for `amount`/`type`, and for the
 * same reason: a signed decimal in a column some reader forgets to sign is a
 * balance that quietly runs backwards. An equal-priced move (two categories at
 * one price, which organizers really do sell) is `direction = 'even'` with
 * `amount = 0`, settled on the spot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_entry_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('race_entry_id')->constrained()->cascadeOnDelete();
            // Denormalised off the entry so the organizer's screens can filter
            // without a join, the same call `race_entries.event_id` makes
            // beside `race_category_id`.
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            // Where the entry sat, and where it is going. Both restrictOnDelete
            // rather than cascade: RaceCategoryController already refuses to
            // delete a category with `taken > 0`, and a transfer row losing
            // either end would leave a ledger entry that cannot say what it
            // moved. `from` is a snapshot of a fact that was true, exactly like
            // `period_name`.
            $table->foreignUuid('from_category_id')->constrained('race_categories')->restrictOnDelete();
            $table->foreignUuid('to_category_id')->constrained('race_categories')->restrictOnDelete();

            // The sale window the prices were read in — the ENTRY's own, not
            // today's. Stored so a receipt can name it, and so a test can tell
            // a build that read the current window from one that did not.
            $table->foreignUuid('race_period_id')->nullable()->constrained()->nullOnDelete();
            $table->string('period_name', 60);

            // Both sides of the comparison, snapshotted. `from_price` is NOT
            // `race_entries.price`: that column is net of a discount, while
            // this is the catalogue price of the category being left, and the
            // difference between them is what makes the row readable a year
            // later. `paid_before` is what the runner had actually handed over
            // at the time — the number the difference was computed from, kept
            // so nobody has to replay the chain to audit one row.
            $table->decimal('from_price', 12, 2);
            $table->decimal('to_price', 12, 2);
            $table->decimal('paid_before', 12, 2);

            // up = the runner owes more, down = money comes back, even = neither.
            $table->string('direction', 8);
            // The difference, always positive. See the class docblock.
            $table->decimal('amount', 12, 2);

            // Money, mirroring `race_entries` column for column so
            // HasManualPayment and PaymentFeeCalculator need no new branch.
            // pending | paid | cancelled | expired | refunded
            $table->string('status', 20)->default('pending');
            $table->string('payment_method', 20)->default('gateway');
            $table->string('payment_channel', 30)->nullable();
            $table->string('payment_type', 30)->nullable();
            $table->decimal('gateway_fee', 12, 2)->default(0);
            // Part of gateway_fee, not on top of it.
            $table->decimal('gateway_tax', 12, 2)->default(0);
            $table->decimal('service_fee', 12, 2)->default(0);

            // Its own documents. A top-up is its own bill: the original receipt
            // keeps saying what it said, which is the whole reason `price` is
            // not rewritten. Null on an even or downward move — a Rp 0 invoice
            // claims a transaction that never happened, the same call every
            // other rail makes for a free order.
            $table->string('invoice_number', 40)->nullable()->unique();
            $table->string('receipt_number', 40)->nullable()->unique();

            $table->string('midtrans_order_id', 100)->nullable()->unique();
            $table->text('midtrans_token')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->text('payment_proof_url')->nullable();
            $table->timestamp('payment_proof_uploaded_at')->nullable();
            $table->timestamp('payment_deadline_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            // Why they moved. Optional, unlike a refund reason: nobody has to
            // approve this, so there is no human waiting to read it — but an
            // organizer looking at a start list that changed wants the note.
            $table->text('reason')->nullable();

            $table->timestamps();

            // "Is this runner mid-move?" — the question every guard asks, and
            // the one the proof page polls on.
            $table->index(['race_entry_id', 'status']);
            // The organizer's own list, and the hourly sweep's scan.
            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_entry_transfers');
    }
};
