<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A certificate template is the organizer's own artwork plus the coordinates of
 * the fields printed on top of it. Templates belong to the organization, not to
 * an event, so one design can be reused across seasons.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->text('background_url');
            $table->string('orientation', 20)->default('landscape'); // landscape|portrait

            // [{ key, x, y, size, color, align, bold, uppercase }] — x/y are
            // percentages of the background, so the layout survives any DPI.
            $table->json('fields');

            $table->timestamps();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_templates');
    }
};
