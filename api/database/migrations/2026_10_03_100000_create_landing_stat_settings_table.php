<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Super-admin overrides for the landing page's "Proof" counters.
     *
     * Overrides only — App\Support\LandingMetrics holds the catalog and every
     * default, so zero rows here is the correct production state and there is
     * deliberately no seeder. Same split as `platform_settings`, and the
     * opposite of `testimonials`/`faqs` next door where the rows ARE the
     * content.
     */
    public function up(): void
    {
        Schema::create('landing_stat_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Validated against LandingMetrics::keys() on write — a plain string
            // column would store a typo happily and then nothing would read it.
            $table->string('metric_key', 40)->unique();
            // All three override columns are nullable, and that is load-bearing:
            // null means "inherit the catalog". Were `is_active` non-null, the
            // first admin save would give every metric a row and freeze today's
            // defaults into the database — a later deploy changing a default
            // would never reach production again.
            $table->string('label')->nullable();
            $table->boolean('is_active')->nullable();
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_stat_settings');
    }
};
