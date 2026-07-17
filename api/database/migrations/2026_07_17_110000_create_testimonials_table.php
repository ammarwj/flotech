<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('quote');
            $table->string('name');
            $table->string('role');
            $table->string('initials', 4);
            $table->string('avatar_preset', 30)->default('brand'); // brand | purple | pink | success | amber | blue
            $table->unsignedTinyInteger('rating')->default(5);     // 1..5
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};
