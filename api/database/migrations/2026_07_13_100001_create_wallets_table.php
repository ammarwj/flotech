<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            // Signed on purpose: a refund of already-withdrawn money pushes
            // available negative, which locks further withdrawals.
            $table->decimal('balance_available', 16, 2)->default(0);
            $table->decimal('balance_pending', 16, 2)->default(0); // held until the event finishes
            $table->decimal('total_earned', 16, 2)->default(0);
            $table->decimal('total_withdrawn', 16, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
