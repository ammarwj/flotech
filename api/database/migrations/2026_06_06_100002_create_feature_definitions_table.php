<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('feature_key', 100)->unique();   // "max_events", "certificate_email"
            $table->string('feature_label');
            $table->string('feature_group', 100)->nullable(); // "event", "ticket", "certificate"
            $table->string('feature_type', 20);              // boolean | numeric | text
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_definitions');
    }
};
