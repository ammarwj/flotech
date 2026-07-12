<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('config_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // tournament_format | tiebreaker | draw_method | knockout_round | sponsor_tier
            $table->string('group', 30);
            $table->string('key', 40);
            $table->string('label');
            $table->text('description')->nullable();
            // Binds the row to code: {engine}, {comparator}, {strategy}, {size},
            // plus a format's default bracket_config under {defaults}.
            $table->json('meta')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_options');
    }
};
