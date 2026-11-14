<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The label an event is served under on the platform's own domain, e.g.
 * `liga-a` for `liga-a.floevent.id`.
 *
 * Stored BESIDE `custom_domain`, not inside it, and the full hostname is written
 * to `custom_domain` as well. That looks redundant until you ask who reads what:
 *
 *  - `custom_domain` is the hostname. DomainService::active() keys its routing
 *    map by it, renderNginxConfig() emits it as a `server_name`, certbot is
 *    handed it, and `domain_certified_at` is a fact about a certificate for it.
 *    A subdomain needs every one of those, identically — which is the whole
 *    reason it is not a second mechanism.
 *  - `subdomain` is the LABEL the organizer typed. It is what the form re-seeds,
 *    what uniqueness is checked on, and the only thing that distinguishes "we
 *    issued this name" from "the organizer brought their own" once both are
 *    sitting in `custom_domain`.
 *
 * That last distinction is load-bearing: releasing a subdomain returns a label
 * to the pool, while releasing a customer's own domain must not pretend we ever
 * held it. A derived check (`str_ends_with($custom_domain, '.floevent.id')`)
 * would be a second reader of that fact, and it breaks outright the day an
 * organizer legitimately owns a name under a domain we also serve.
 *
 * Unique, like `custom_domain` and unlike `slug`: a label has no organization to
 * be scoped by — whoever holds `liga-a` holds it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('subdomain', 40)->nullable()->unique()->after('custom_domain');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique(['subdomain']);
            $table->dropColumn('subdomain');
        });
    }
};
