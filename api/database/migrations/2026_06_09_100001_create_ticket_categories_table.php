<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->integer('quota')->nullable();          // null = unlimited
            $table->integer('sold')->default(0);
            $table->timestamp('sale_start')->nullable();
            $table->timestamp('sale_end')->nullable();
            $table->json('benefits')->nullable();
            $table->boolean('is_transferable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_categories');
    }
};
