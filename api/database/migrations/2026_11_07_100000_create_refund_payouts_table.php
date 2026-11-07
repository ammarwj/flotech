<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The money owed back after a refund, and who still has to move it.
 *
 * A refund reverses the organizer's wallet credit the instant it is granted —
 * their balance drops, the place goes back on offer, and then nothing happened
 * at all. The buyer's money sits in the platform's own Midtrans account, so
 * somebody has to transfer it by hand, and before this table nobody was told:
 * there was no notification on any refund rail, race entries were not even
 * listed on `/admin/payments`, and no column anywhere recorded that a transfer
 * back had been made. `withdrawals` has carried `proof_url` +
 * `transfer_reference` + `completed_at` since the wallet shipped; this is that
 * pattern applied to the debt pointing the other way.
 *
 * The chain it records has two hops, because the platform does not hold the
 * participant's bank details and never has: super admin transfers to the
 * ORGANIZER's registered account, the organizer forwards to their own
 * participant. Both hops carry proof, and the second one is why the runner's
 * own page can say where their money is.
 *
 * > **Invariant: the amount is READ from the ledger row, never recomputed.**
 * > `RefundPayoutService::record()` stores the reversal's own `amount`, the
 * > value `WalletService::reverseCredit()` returned. Rebuilding it from
 * > `price + service_fee` or `gross_amount − fee` is a second piece of
 * > arithmetic that drifts from what actually left the wallet: `price` is
 * > already net of any discount, and the fee percentages in `/admin/settings`
 * > can be edited after a payment settles. The three numbers that matter — what
 * > left the wallet, what the platform transfers, what the participant receives
 * > — are one number, and reading it from the row that moved it is what keeps
 * > them one. Test it by COMPARING a discounted entry with a full-price one in
 * > the same category: `amount` must equal each one's ledger debit, not the
 * > category price.
 *
 * > **Invariant: completing a payout must not touch the wallet.** The
 * > organizer's balance has just gone DOWN by this amount; writing the transfer
 * > back as a wallet credit raises it again and cancels the refund in effect,
 * > crediting them for the very money being returned. Same reason already
 * > written for manual buyer transfers: money that does not pass through the
 * > platform has no business in the ledger. `wallet_transaction_id` points at
 * > the reversal for audit, and is the only link between the two. Test it by
 * > COMPARING the balance before the refund, after it, and after `complete()`
 * > — the last two must be EQUAL; asserting "the balance dropped on refund"
 * > alone passes even when completing credits it straight back.
 *
 * `status` is `pending|completed` and deliberately has no `processing` arm,
 * unlike `withdrawals`: that is a payout queue with volume, worked through in
 * batches. This is one transfer with one proof, and a third value would be a
 * state nobody ever wrote the meaning of.
 *
 * No seeder and no backfill. Refunds granted before this table exist have
 * already been settled by hand or not at all, and inventing rows for them
 * would put real transfer debts in front of a super admin with no way to tell
 * which were already paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Source-keyed the same way wallet_transactions is, which is what
            // lets one table serve all three refund rails. Columns on each of
            // `race_entries`, `teams` and `ticket_orders` would be the same
            // three fields spelled three times, and the queue reading them
            // would be a union of three queries — the shape
            // EventDiscountCodeService::usage() already rejected.
            $table->string('source_type', 20); // race_entry|team|ticket_order
            $table->uuid('source_id');

            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            // Null for a refund whose source carries no event — none today, but
            // the column follows wallet_transactions rather than inventing a
            // stricter rule than the ledger it mirrors.
            $table->foreignUuid('event_id')->nullable()->constrained('events')->nullOnDelete();

            // The reversal this debt came from. nullOnDelete rather than
            // cascade: losing the ledger row must not erase the record that
            // money is owed.
            $table->foreignUuid('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();

            // Read from the reversal — see the class docblock.
            $table->decimal('amount', 12, 2);

            // Who the money is ultimately for, so the queue can be read without
            // joining out to four different source tables.
            $table->string('recipient_name', 150);
            $table->text('reason')->nullable();

            $table->string('status', 20)->default('pending'); // pending|completed

            // Immutable snapshot of the organizer's payout destination, the
            // same reasoning as `withdrawals`: editing or deleting the account
            // later must not rewrite where past money was sent. `account_type`
            // rides along as its own copy for the same reason it does there —
            // "Nomor rekening" printed over a phone number sends people looking
            // for a field their GoPay app does not have.
            $table->string('account_type', 10)->default('bank');
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_code', 20)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('account_holder', 150)->nullable();

            // Hop one: platform → organizer.
            $table->text('proof_url')->nullable();
            $table->string('transfer_reference', 100)->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignUuid('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();

            // Hop two: organizer → participant. On this row rather than in a
            // second table because it is an attribute of the same transfer, and
            // a join is a poor way to answer "has my money arrived?" — the
            // question the runner's own page asks every time it opens.
            $table->text('forwarded_proof_url')->nullable();
            $table->string('forwarded_reference', 100)->nullable();
            $table->foreignUuid('forwarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('forwarded_at')->nullable();

            $table->timestamps();

            // One refund, one transfer debt. This is also what makes
            // RefundPayoutService::record() idempotent without a read-modify-
            // write, the same backstop WalletService::record() leans on.
            $table->unique(['source_type', 'source_id']);
            $table->index(['status', 'created_at']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_payouts');
    }
};
