<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payout destinations can be an e-wallet (GoPay/DANA/…) as well as a bank
 * account. One column on both tables, defaulting to `bank`, so every existing
 * row keeps meaning exactly what it meant before.
 *
 * `withdrawals` gets its own copy rather than reading through
 * `bank_account_id`: the rest of that row is already an immutable snapshot, and
 * switching from a bank to an e-wallet later must not relabel past transfers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('account_type', 20)->default('bank')->after('organization_id');
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->string('account_type', 20)->default('bank')->after('bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn('account_type');
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropColumn('account_type');
        });
    }
};
