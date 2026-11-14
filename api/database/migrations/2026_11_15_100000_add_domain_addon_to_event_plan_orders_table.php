<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The domain add-on: a paid extra that puts an event on its own hostname.
 *
 * It rides on `event_plan_orders` rather than a table of its own because every
 * question an order answers is the same question here — which rail, what fee,
 * which invoice number, is the proof verified, has it expired — and a second
 * table would mean a second writer of money with its own checkout, sweep and
 * PDF to keep in step. `plan_id` was already nullable, so an add-on bought on
 * its own is simply an order with no plan.
 *
 * `domain_event_id`, NOT `event_id`. The latter is unique: "one credit, one
 * event" is a database fact, and an add-on bought for an event that already
 * spent a credit would collide with the order holding it. Overloading it would
 * also give the column two meanings — "the credit this order was spent on" and
 * "the event this domain is for" — which is the nullable-pair shape this
 * codebase keeps refusing. Not unique either: an event may legitimately buy a
 * domain more than once (a name change, a renewal), and each purchase is its own
 * receipt.
 *
 * Prices are SNAPSHOT, not read back off PlatformSettings. Same reason
 * `withdrawals` snapshots its admin fee and `event_plan_orders` snapshots its
 * discount: a super admin raising the .id price tomorrow must not rewrite an
 * invoice that has already gone out. `domain_addon_amount` is the pair's sum and
 * is what `amount` already includes — it exists so a document can print the
 * add-on line without re-deriving it from a plan price it may not have.
 *
 * There is no `domain_addon_status` column. Whether the domain is live is a fact
 * about a certificate on disk (see Event::domainStatus), and what is left — "has
 * a super admin actually bought the name yet" — is exactly what
 * `domain_fulfilled_at` records. A third status would be a second reader of the
 * first two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_plan_orders', function (Blueprint $table) {
            // own | register | subdomain — see App\Support\DomainAddons. Null on
            // every ordinary plan order, which is what "this order has no add-on"
            // means; nothing else has to be consulted.
            $table->string('domain_kind', 16)->nullable()->after('plan_id');

            // What the organizer asked for, as they asked for it: a full
            // hostname for `own`/`register`, and for `subdomain` the full
            // hostname too (label + platform domain, composed at checkout by
            // DomainNames::hostFor). Stored rather than recomposed because the
            // platform domain is config and config changes — an invoice must
            // still name the host that was actually bought.
            //
            // Nullable for `register`, and deliberately so: an organizer may buy
            // "a .id domain" and only tell us the name afterwards, or ask us to
            // pick. The super admin fills it in when they fulfil the order.
            $table->string('domain_name', 253)->nullable()->after('domain_kind');

            $table->decimal('domain_setup_amount', 12, 2)->default(0)->after('domain_name');
            $table->decimal('domain_register_amount', 12, 2)->default(0)->after('domain_setup_amount');
            $table->decimal('domain_addon_amount', 12, 2)->default(0)->after('domain_register_amount');

            // The event this domain is for. Null while an add-on bought
            // alongside a plan waits for its event to exist — stamped by
            // EventController::store() in the same transaction that claims the
            // credit, so the two can never disagree about which event was paid
            // for.
            $table->foreignUuid('domain_event_id')->nullable()->after('domain_addon_amount')
                ->constrained('events')->nullOnDelete();

            // Only the `register` kind ever waits on a human: someone has to buy
            // the name at a registrar. `own` and `subdomain` are fulfilled the
            // moment they are paid for, and are stamped as such by
            // EventPlanOrderService::activate() rather than left null — an
            // unstamped row is a queue entry, and a queue that lists work nobody
            // has to do is a queue people stop reading.
            $table->timestamp('domain_fulfilled_at')->nullable()->after('domain_event_id');
            $table->foreignUuid('domain_fulfilled_by')->nullable()->after('domain_fulfilled_at')
                ->constrained('users')->nullOnDelete();

            // What the super admin typed when they bought (or refused to buy)
            // the name — a registrar order reference, or why it could not be
            // registered. Shown to the organizer: "domain itu sudah diambil
            // orang lain" is the only thing that explains a paid order with no
            // domain on it.
            $table->text('domain_note')->nullable()->after('domain_fulfilled_by');
        });

        Schema::table('event_plan_orders', function (Blueprint $table) {
            // The fulfilment queue scans for paid `register` orders with no
            // stamp. Plain, not partial, so the SQLite test suite gets it too —
            // same call as the wallet_transactions event index.
            $table->index(['domain_kind', 'domain_fulfilled_at'], 'epo_domain_queue_index');
        });
    }

    public function down(): void
    {
        Schema::table('event_plan_orders', function (Blueprint $table) {
            $table->dropIndex('epo_domain_queue_index');
            $table->dropConstrainedForeignId('domain_event_id');
            $table->dropConstrainedForeignId('domain_fulfilled_by');
            $table->dropColumn([
                'domain_kind',
                'domain_name',
                'domain_setup_amount',
                'domain_register_amount',
                'domain_addon_amount',
                'domain_fulfilled_at',
                'domain_note',
            ]);
        });
    }
};
