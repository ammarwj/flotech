<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('ticket_category_id')->constrained('ticket_categories')->cascadeOnDelete();
            $table->foreignUuid('buyer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('buyer_name');
            $table->string('buyer_email');
            $table->string('buyer_phone', 30)->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total_price', 12, 2);
            $table->decimal('platform_fee', 12, 2)->default(0);
            $table->string('status', 20)->default('pending'); // pending|paid|cancelled|refunded
            $table->string('midtrans_order_id', 100)->nullable()->unique();
            $table->text('midtrans_token')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_orders');
    }
};
