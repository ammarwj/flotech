<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();

            // Round number (round-robin) or knockout stage depth.
            $table->unsignedSmallInteger('round')->default(1);
            $table->string('group_name', 10)->nullable();
            $table->unsignedSmallInteger('order')->default(0); // ordering within a round

            $table->foreignUuid('home_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignUuid('away_team_id')->nullable()->constrained('teams')->nullOnDelete();

            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->string('venue')->nullable();
            // scheduled | ongoing | finished | cancelled
            $table->string('status', 20)->default('scheduled');

            $table->timestamps();

            $table->index(['event_id', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
