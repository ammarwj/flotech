<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->nullOnDelete();

            $table->string('type', 10);      // credit | debit
            $table->string('category', 30);  // ticket_sale|registration_fee|refund|withdrawal|withdrawal_reversal|adjustment
            $table->string('status', 20);    // pending | available | cancelled

            $table->decimal('amount', 12, 2);                   // magnitude moving the balance, always > 0
            $table->decimal('gross_amount', 12, 2)->default(0); // what the buyer paid / admin transfers
            $table->decimal('fee_amount', 12, 2)->default(0);   // platform fee / withdrawal admin fee

            $table->string('source_type', 40)->nullable();      // ticket_order | team | withdrawal
            $table->uuid('source_id')->nullable();

            $table->timestamp('available_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();

            // The idempotency key. A re-delivered Midtrans webhook cannot credit
            // the same order twice. Manual adjustments have null sources, which
            // both Postgres and SQLite exempt from the unique constraint.
            $table->unique(['source_type', 'source_id', 'category'], 'wallet_tx_source_unique');
            $table->index(['wallet_id', 'created_at']);
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
