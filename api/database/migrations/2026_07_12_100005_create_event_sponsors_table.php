<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_sponsors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('name');
            $table->text('logo_url');
            $table->text('website_url')->nullable();
            // Billing tier — drives how prominently the logo is shown.
            $table->string('tier', 20)->default('supporting'); // main | official | supporting
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sponsors');
    }
};
