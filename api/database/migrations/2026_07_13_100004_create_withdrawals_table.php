<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->foreignUuid('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('reference', 30)->unique(); // WD-XXXXXXXX

            $table->decimal('amount', 12, 2);            // what the organizer receives
            $table->decimal('admin_fee', 12, 2)->default(0);
            $table->decimal('total_debit', 12, 2);       // amount + admin_fee, what leaves the wallet
            $table->decimal('minimum_at_request', 12, 2)->default(0);

            $table->string('status', 20)->default('pending'); // pending|processing|completed|rejected

            // Immutable snapshot: editing the bank account later must not
            // rewrite where past money was sent.
            $table->string('bank_name', 100);
            $table->string('bank_code', 20)->nullable();
            $table->string('account_number', 50);
            $table->string('account_holder', 150);

            $table->text('note')->nullable();
            $table->text('proof_url')->nullable();
            $table->string('transfer_reference', 100)->nullable();
            $table->text('admin_note')->nullable();

            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['organization_id', 'status']);
        });

        // DB backstop for "one active request per org". WithdrawalService also
        // checks this under a row lock, but lockForUpdate() is a no-op on
        // SQLite — this partial index is what actually holds in the test suite.
        DB::statement(
            "CREATE UNIQUE INDEX withdrawals_one_active_per_org ON withdrawals (organization_id)
             WHERE status IN ('pending', 'processing')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
