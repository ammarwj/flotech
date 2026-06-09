<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('ticket_orders')->cascadeOnDelete();
            $table->foreignUuid('ticket_category_id')->constrained('ticket_categories')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('qr_code')->unique();
            $table->string('holder_name')->nullable();
            $table->boolean('is_used')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->foreignUuid('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['event_id', 'is_used']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
