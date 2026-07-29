<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where an organizer transfers when they buy a plan and the payment gateway
     * is switched off. This is the *platform's* account, not an organizer's —
     * subscription money is our revenue and never passes through a wallet.
     *
     * Same reason the rest of this table isn't in `platform_settings`:
     * PlatformSettings::get() casts to float|int|bool, and a bank name pushed
     * through it comes back as 0.0.
     *
     * Column names deliberately mirror `bank_accounts` so the JSON shape is
     * identical to PublicBankAccountResource and the web client can reuse its
     * PublicBankAccount type and ManualTransferPanel without an adapter.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('bank_name', 60)->nullable()->after('sales_email');
            $table->string('bank_code', 10)->nullable()->after('bank_name');
            $table->string('account_number', 40)->nullable()->after('bank_code');
            $table->string('account_holder', 100)->nullable()->after('account_number');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'bank_code', 'account_number', 'account_holder']);
        });
    }
};
