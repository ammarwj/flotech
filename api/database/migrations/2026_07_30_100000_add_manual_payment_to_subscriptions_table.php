<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manual transfer for plan payments — the fallback while a super admin has
     * the payment gateway switched off, mirroring what ticket orders and teams
     * already carry.
     *
     * The one difference worth naming: the destination is the *platform's* bank
     * account (site_settings), not the organizer's. So the invariant those two
     * migrations exist to protect — "manual money must never touch the wallet"
     * — has nothing to say here: subscription money is our revenue and never
     * touches an organizer wallet on either rail.
     *
     * No new `status` value. "Awaiting verification" is derived from the proof
     * columns; the reasoning is written out in HasManualPayment.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('payment_method', 10)->default('gateway')->after('amount'); // gateway|manual
            $table->text('payment_proof_url')->nullable()->after('midtrans_token');
            $table->timestamp('payment_proof_uploaded_at')->nullable()->after('payment_proof_url');
            $table->timestamp('payment_deadline_at')->nullable()->after('payment_proof_uploaded_at');
            $table->text('rejected_reason')->nullable()->after('payment_deadline_at');
            $table->foreignUuid('verified_by')->nullable()->after('rejected_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');

            // Both queues read on this pair: the super admin's verification list
            // and the abandoned-invoice sweep.
            $table->index(['payment_method', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['payment_method', 'status']);
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn([
                'payment_method',
                'payment_proof_url',
                'payment_proof_uploaded_at',
                'payment_deadline_at',
                'rejected_reason',
                'verified_at',
            ]);
        });
    }
};
