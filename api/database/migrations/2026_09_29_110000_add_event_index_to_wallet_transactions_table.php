<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index for the minimum-waiver aggregate in Wallet::exemptSourceBalance().
 *
 * That query drives from the wallet's own rows and joins `events` by primary
 * key, so it needs to reach one wallet's available credits by event — and none
 * of the existing indexes help: `foreignUuid()->constrained()` creates no index
 * in Postgres, and the existing ones are (wallet_id, created_at),
 * (status, available_at), and the source-idempotency unique.
 *
 * A plain index rather than a partial one, so the SQLite test suite gets it too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->index(['wallet_id', 'status', 'event_id'], 'wallet_tx_wallet_status_event_idx');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('wallet_tx_wallet_status_event_idx');
        });
    }
};
