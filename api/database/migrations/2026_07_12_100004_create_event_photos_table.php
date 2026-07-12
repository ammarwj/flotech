<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            // Photos are grouped into albums by name ("Opening Ceremony",
            // "Final"). Null = the event's default album.
            $table->string('album', 100)->nullable();
            $table->text('photo_url');
            $table->string('caption')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'album']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_photos');
    }
};
