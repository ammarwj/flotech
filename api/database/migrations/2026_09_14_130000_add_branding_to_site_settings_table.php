<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The platform's own logo and favicon, uploaded from /admin/site-settings.
     *
     * Here rather than in `platform_settings` for the reason that table's own
     * migration already gives: PlatformSettings::get() casts every value to
     * float|int|bool, so a URL pushed through it comes back as 0.0. And not in
     * config/brand.php either — that file is deployed, and the whole point of
     * this is to change the branding without a deploy.
     *
     * Null means "use the built-in mark", which is what every surface falls
     * back to. A platform that has never uploaded anything renders exactly as
     * it does today.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('logo_url', 2048)->nullable()->after('sales_email');
            $table->string('favicon_url', 2048)->nullable()->after('logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['logo_url', 'favicon_url']);
        });
    }
};
