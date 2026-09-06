<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The domain a published event is served on, instead of /{org}/{event}.
 *
 * Unique GLOBALLY, unlike `slug` — that one is unique per organization, because
 * two organizers may both run a "liga-2026". A hostname has no organization to
 * be scoped by: whoever holds it holds it.
 *
 * There is no `domain_status` column on purpose. Whether a domain is live is a
 * fact about a certificate file on disk, and a stored status drifts from it the
 * moment a cert is deleted or a renewal fails — the same reason match bans are
 * derived rather than saved. Event::domainStatus() reads these three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('custom_domain', 253)->nullable()->unique()->after('slug');
            // DNS was seen pointing at us. Kept apart from the certificate: a
            // domain can verify and still fail issuance (rate limit, ACME
            // outage), and the admin needs to see which half went wrong.
            $table->timestamp('domain_verified_at')->nullable()->after('custom_domain');
            $table->timestamp('domain_certified_at')->nullable()->after('domain_verified_at');
            // Last failure, shown verbatim in /admin/events — the certbot or DNS
            // message is the only clue why activation did not take.
            $table->text('domain_error')->nullable()->after('domain_certified_at');
            // Throttles retries. Let's Encrypt allows 5 failures per hostname per
            // hour and counts them per account, so one misconfigured domain
            // retrying in a loop would lock out every other domain we hold.
            $table->timestamp('domain_attempted_at')->nullable()->after('domain_error');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'custom_domain',
                'domain_verified_at',
                'domain_certified_at',
                'domain_error',
                'domain_attempted_at',
            ]);
        });
    }
};
