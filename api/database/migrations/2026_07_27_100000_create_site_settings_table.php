<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How to reach flo-event itself, shown in the landing footer and edited by
     * super_admin at /admin/site-settings.
     *
     * A single row rather than key/value in `platform_settings`: that table is
     * payout policy read through PlatformSettings::get(), which casts to
     * float|int|bool — a URL pushed through it would come back as 0.0. Typed
     * columns here mirror what `organizations` already stores per organizer.
     */
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();
            // CTA of the Professional plan card. Empty = fall back to contact_email.
            $table->string('sales_email')->nullable();
            // One JSON map ({instagram: url, ...}) for the same reason as
            // organizations.social_links: adding a platform shouldn't cost a migration.
            $table->json('social_links')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
