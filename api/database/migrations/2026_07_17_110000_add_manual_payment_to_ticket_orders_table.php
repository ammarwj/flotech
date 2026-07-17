<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual bank transfer: the fallback used while the payment gateway is off.
 * The buyer transfers to the organizer's own account and uploads proof; an org
 * admin approves it. No money passes through the platform, so such an order is
 * never credited to a wallet and carries no platform fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_orders', function (Blueprint $table) {
            // Snapshot, not derived: the gateway can be switched back on, and an
            // order born during an outage must stay manual for the rest of its life.
            $table->string('payment_method', 10)->default('gateway')->after('platform_fee'); // gateway|manual

            $table->text('payment_proof_url')->nullable()->after('midtrans_token');
            $table->timestamp('payment_proof_uploaded_at')->nullable()->after('payment_proof_url');
            // Manual orders get no Midtrans expiry webhook, so their reserved
            // quota would be held forever. `tickets:expire-manual` uses this.
            $table->timestamp('payment_deadline_at')->nullable()->after('payment_proof_uploaded_at');
            $table->text('rejected_reason')->nullable()->after('payment_deadline_at');
            $table->foreignUuid('verified_by')->nullable()->after('rejected_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');

            // The expiry sweep and the organizer's verification queue.
            $table->index(['payment_method', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('ticket_orders', function (Blueprint $table) {
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
