<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 100);
            $table->string('sport_type', 20);        // football | futsal | badminton | padel | volleyball
            $table->string('tournament_format', 20); // league | knockout_single | knockout_double | hybrid
            $table->string('status', 30)->default('draft'); // draft|open|registration_closed|ongoing|finished|cancelled
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamp('registration_open')->nullable();
            $table->timestamp('registration_close')->nullable();
            $table->string('location_name')->nullable();
            $table->text('location_address')->nullable();
            $table->text('description')->nullable();
            $table->text('banner_url')->nullable();
            $table->integer('max_teams')->nullable();
            $table->decimal('registration_fee', 12, 2)->default(0);
            $table->json('rules_config')->nullable();
            $table->json('bracket_config')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
