<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registration-fee counterpart of the ticket_orders manual-payment columns.
 * Note `teams` has two independent status columns: `status` is the organizer
 * approving the team into the tournament, `payment_status` is the money. These
 * columns belong to the second one — approving a payment proof never admits a
 * team, and vice versa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('payment_method', 10)->default('gateway')->after('platform_fee'); // gateway|manual

            $table->text('payment_proof_url')->nullable()->after('paid_at');
            $table->timestamp('payment_proof_uploaded_at')->nullable()->after('payment_proof_url');
            $table->timestamp('payment_deadline_at')->nullable()->after('payment_proof_uploaded_at');
            $table->text('rejected_reason')->nullable()->after('payment_deadline_at');
            $table->foreignUuid('verified_by')->nullable()->after('rejected_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');

            $table->index(['payment_method', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['payment_method', 'payment_status']);
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
