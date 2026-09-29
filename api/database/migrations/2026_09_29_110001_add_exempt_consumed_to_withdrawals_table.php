<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of this payout came out of the minimum-waived balance.
 *
 * The waiver is a stock (credits from ended events) and withdrawing is a flow,
 * so the two cannot be reconciled from aggregates alone: withdrawal debits carry
 * no `event_id`, which means an ordinary payout from a live event would appear to
 * drain the waiver and kill it permanently. Recording the draw here — the same
 * snapshot pattern as `minimum_at_request` and `admin_fee` beside it — is what
 * makes "how much waiver is left" answerable.
 *
 * No second "was waived" flag: a rejected or cancelled payout stops consuming
 * because its own `status` leaves the sum, and reverseWithdrawal() has already
 * put the money back in the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->decimal('exempt_consumed', 12, 2)->default(0)->after('minimum_at_request');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropColumn('exempt_consumed');
        });
    }
};
