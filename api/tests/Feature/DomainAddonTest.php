<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventPlanOrder;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Support\DomainAddons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The domain add-on: three kinds, one order shape, white-label as a consequence.
 *
 * Nearly every test here COMPARES two things that have to come out different,
 * because the failure modes are all "the branch never ran": an add-on priced
 * after the fee, a credit pool that swallows an add-on-only order, a catalogue
 * that lists a white-labelled event anyway, a subdomain stored as a bare label.
 * Asserting one half of each pair passes just as happily against all of those.
 */
class DomainAddonTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The prices are super-admin settings, so a test that reads them has to
        // pin them — otherwise it asserts against whatever config/domains.php
        // happens to say today.
        PlatformSettings::put([
            'domain_setup_fee_amount' => 100_000,
            'domain_register_com_amount' => 300_000,
            'domain_register_id_amount' => 370_000,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /** A paid plan the organizer can buy, priced so the add-on is visible beside it. */
    private function sellablePlan(float $price = 350_000): Plan
    {
        $plan = $this->fullPlan();
        $plan->update(['price' => $price, 'is_active' => true, 'is_public' => true]);

        return $plan;
    }

    /**
     * A plan checkout. `payment_channel` defaults to one of the live channels:
     * every amount here is non-zero and the gateway is up, so
     * resolvePayment() demands a channel — leaving it out tests nothing but the
     * channel requirement.
     */
    private function checkout(User $owner, Organization $org, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/checkout", [
                'payment_channel' => 'va',
                ...$payload,
            ]);
    }

    /** An add-on bought on its own, for an event that already exists. */
    private function buyDomain(User $owner, Organization $org, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/domain-checkout", [
                'payment_channel' => 'va',
                ...$payload,
            ]);
    }

    // ---- pricing ------------------------------------------------------------

    public function test_setup_fee_is_charged_on_all_three_kinds_and_registration_only_on_one(): void
    {
        // Compared rather than asserted one at a time: a quote() that ignored
        // `$kind` and always added the registration price would pass a
        // `register` assertion on its own, and so would one that never added it.
        $this->assertSame(100_000.0, DomainAddons::quote('own', 'liga.test')['total']);
        $this->assertSame(100_000.0, DomainAddons::quote('subdomain', 'liga.floevent.id')['total']);
        $this->assertSame(470_000.0, DomainAddons::quote('register', 'liga.id')['total']);
        // Per TLD, not one registration price: .com and .id are different
        // numbers, and a single setting would make one of them wrong.
        $this->assertSame(400_000.0, DomainAddons::quote('register', 'liga.com')['total']);
    }

    public function test_an_unsold_tld_is_refused_rather_than_quoted_free(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $plan = $this->sellablePlan();

        // registrationPrice() returns 0 for a TLD we do not resell, so without
        // the refusal this would bill a .xyz registration as the setup fee
        // alone — the organizer pays 100k for a domain that costs us money.
        $this->checkout($owner, $org, [
            'plan_id' => $plan->id,
            'domain_kind' => 'register',
            'domain_name' => 'liga.xyz',
        ])->assertStatus(422)->assertJsonValidationErrors('domain_name');

        $this->assertSame(0, EventPlanOrder::count());
    }

    // ---- the money ----------------------------------------------------------

    public function test_addon_is_added_before_the_gateway_fee_is_calculated(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $plan = $this->sellablePlan();

        // `ewallet` on purpose: its gateway fee is a PERCENTAGE. On `va` the fee
        // is flat, so a build that added the add-on after the fee would quote
        // exactly the same number and this comparison could not tell them apart.
        $plain = $this->checkout($owner, $org, [
            'plan_id' => $plan->id,
            'payment_channel' => 'ewallet',
        ])->assertStatus(201);

        $withAddon = $this->checkout($owner, $org, [
            'plan_id' => $plan->id,
            'payment_channel' => 'ewallet',
            'domain_kind' => 'register',
            'domain_name' => 'liga-b.id',
        ])->assertStatus(201);

        $plainFee = (float) $plain->json('data.plan_order.gateway_fee');
        $addonFee = (float) $withAddon->json('data.plan_order.gateway_fee');

        $this->assertGreaterThan(
            $plainFee,
            $addonFee,
            'Fee gateway persentase harus dihitung atas harga yang sudah memuat add-on.',
        );

        // And the add-on is inside `amount`, not bolted on beside it: the
        // upgrade pricing in paidTowardsPlan() reads `amount`, and the invoice
        // reconstructs the plan price by subtracting the add-on back out.
        $this->assertSame(820_000.0, (float) $withAddon->json('data.plan_order.amount'));
        $this->assertSame(470_000.0, (float) $withAddon->json('data.plan_order.domain_addon_amount'));
    }

    public function test_a_discount_code_comes_off_the_plan_and_never_off_the_addon(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $plan = $this->sellablePlan();

        \App\Models\DiscountCode::create([
            'code' => 'HALF',
            'discount_type' => 'percent',
            'discount_value' => 50,
            'is_active' => true,
        ]);

        $response = $this->checkout($owner, $org, [
            'plan_id' => $plan->id,
            'discount_code' => 'HALF',
            'domain_kind' => 'subdomain',
            'domain_name' => 'liga-c',
        ])->assertStatus(201);

        // Compared against the alternative reading: 50% of (350k + 100k) would
        // be 225k off and an amount of 225k. The code is sold against a *plan*,
        // so it may only touch the plan's own price.
        $this->assertSame(175_000.0, (float) $response->json('data.plan_order.discount_amount'));
        $this->assertSame(275_000.0, (float) $response->json('data.plan_order.amount'));
    }

    // ---- the credit pool ----------------------------------------------------

    public function test_an_addon_only_order_is_never_spendable_as_a_plan_credit(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org);

        $this->buyDomain($owner, $org, [
                'event_id' => $event->id,
                'domain_kind' => 'subdomain',
                'domain_name' => 'liga-d',
            ])
            ->assertStatus(201);

        $addonOrder = EventPlanOrder::whereNull('plan_id')->firstOrFail();
        $this->assertNull($addonOrder->plan_id);
        $this->assertSame('paid', $addonOrder->status, 'Tanpa Midtrans, order langsung lunas.');

        // The whole point: a plan is the substance of a credit, and this order
        // has none. Without the `plan_id` guard in scopeUnconsumed() this would
        // be the oldest credit in the pool, and POST /events would spend it —
        // handing the organizer an event with `plan_id` null, every gate shut,
        // while the plan they actually bought sits unspent.
        $this->assertSame(0, $org->planOrders()->unconsumed()->count());

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", [
                'name' => 'Liga Baru',
                'sport_type' => 'football',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-10',
                'categories' => [['name' => 'A', 'tournament_format' => 'league']],
            ])
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'plan_order_required');

        // Compare: a real credit in the same pool IS spendable. Asserting only
        // the refusal above would pass just as well in a build where nothing is
        // ever claimable.
        $this->creditFor($org);
        $this->assertSame(1, $org->planOrders()->unconsumed()->count());
    }

    // ---- the three kinds ----------------------------------------------------

    public function test_a_paid_subdomain_is_attached_as_a_full_hostname_with_its_label_beside_it(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org);

        $this->buyDomain($owner, $org, [
                'event_id' => $event->id,
                'domain_kind' => 'subdomain',
                // Typed as a pasted URL, which is what someone who copied it out
                // of a browser bar sends.
                'domain_name' => 'https://Liga-E.floevent.id/',
            ])
            ->assertStatus(201);

        $event->refresh();

        // Both columns, and they are not the same string. `custom_domain` is
        // what the nginx vhost, the certificate and the routing map all read;
        // `subdomain` is the label, and a non-null one is the only thing that
        // says we issued the name rather than the organizer bringing it.
        // Asserting either alone passes against a build that stores a bare
        // label as the hostname — which would generate a `server_name liga-e`.
        $this->assertSame('liga-e.floevent.id', $event->custom_domain);
        $this->assertSame('liga-e', $event->subdomain);
    }

    public function test_a_register_order_waits_for_a_human_while_the_other_kinds_do_not(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org);
        $other = $this->eventOn($org);

        // `own`: the organizer holds the name, so there is nothing for us to buy
        // — fulfilled by being paid for, and attached at once (which is what
        // publishes the port-80 vhost the ACME challenge needs).
        $this->buyDomain($owner, $org, [
                'event_id' => $other->id,
                'domain_kind' => 'own',
                'domain_name' => 'liga-own.test',
            ])
            ->assertStatus(201);

        // `register`: nobody holds the name yet. Attaching one we have not
        // bought would publish a vhost for a hostname that resolves elsewhere.
        $this->buyDomain($owner, $org, [
                'event_id' => $event->id,
                'domain_kind' => 'register',
                'domain_name' => 'liga-reg.id',
            ])
            ->assertStatus(201);

        $own = EventPlanOrder::where('domain_kind', 'own')->firstOrFail();
        $register = EventPlanOrder::where('domain_kind', 'register')->firstOrFail();

        // The comparison is the test. A build that stamped everything would pass
        // the `own` assertion; one that stamped nothing would pass neither, but
        // would leave the queue full of work nobody has to do.
        $this->assertNotNull($own->domain_fulfilled_at);
        $this->assertFalse($own->isAwaitingDomainFulfilment());
        $this->assertSame('liga-own.test', $other->refresh()->custom_domain);

        $this->assertNull($register->domain_fulfilled_at);
        $this->assertTrue($register->isAwaitingDomainFulfilment());
        $this->assertNull($event->refresh()->custom_domain, 'Nama yang belum dibeli tidak boleh dipasang.');
    }

    public function test_super_admin_fulfilment_attaches_the_name_that_was_actually_registered(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org);

        $this->buyDomain($owner, $org, [
                'event_id' => $event->id,
                'domain_kind' => 'register',
                'domain_name' => 'liga-wanted.id',
            ])
            ->assertStatus(201);

        $order = EventPlanOrder::where('domain_kind', 'register')->firstOrFail();

        $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/domain-orders')
            ->assertOk()
            ->assertJsonCount(1, 'data.pending')
            ->assertJsonCount(0, 'data.fulfilled');

        // The name they got is not the name they asked for — theirs was taken.
        // The note is the only thing on the organizer's screen that accounts
        // for it.
        $this->actingAs($this->superAdmin(), 'api')
            ->postJson("/api/v1/admin/domain-orders/{$order->id}/fulfil", [
                'domain_name' => 'liga-got.id',
                'domain_note' => 'liga-wanted.id sudah diambil orang lain.',
            ])
            ->assertOk();

        $this->assertSame('liga-got.id', $event->refresh()->custom_domain);
        // Still null: we issued nothing under our own zone, so returning a
        // label to the pool would be returning one we never held.
        $this->assertNull($event->subdomain);

        $order->refresh();
        $this->assertNotNull($order->domain_fulfilled_at);
        $this->assertSame('liga-got.id', $order->domain_name);
        $this->assertFalse($order->isAwaitingDomainFulfilment());

        // And it leaves the queue for the log. Comparing both counts, because a
        // build that stamped the row without moving it would pass a bare
        // "pending is empty".
        $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/domain-orders')
            ->assertOk()
            ->assertJsonCount(0, 'data.pending')
            ->assertJsonCount(1, 'data.fulfilled');
    }

    public function test_fulfilment_is_super_admin_only(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org);

        $this->buyDomain($owner, $org, [
                'event_id' => $event->id,
                'domain_kind' => 'register',
                'domain_name' => 'liga-auth.id',
            ])
            ->assertStatus(201);

        $order = EventPlanOrder::where('domain_kind', 'register')->firstOrFail();

        // The organizer who paid is exactly the party who must not be able to
        // mark their own domain registered: doing so attaches a hostname and
        // spends Let's Encrypt quota the whole platform shares.
        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/admin/domain-orders/{$order->id}/fulfil", [
                'domain_name' => 'liga-auth.id',
            ])
            ->assertForbidden();

        $this->assertNull($order->refresh()->domain_fulfilled_at);

        // Compare: the same payload from a super admin lands. Asserting the 403
        // alone passes against a route nobody can reach.
        $this->actingAs($this->superAdmin(), 'api')
            ->postJson("/api/v1/admin/domain-orders/{$order->id}/fulfil", [
                'domain_name' => 'liga-auth.id',
            ])
            ->assertOk();
    }

    // ---- name collisions ----------------------------------------------------

    public function test_a_name_another_order_already_paid_for_cannot_be_bought_twice(): void
    {
        $first = User::factory()->create();
        $firstOrg = $this->orgFor($first, 'First');
        $firstEvent = $this->eventOn($firstOrg);

        $second = User::factory()->create();
        $secondOrg = $this->orgFor($second, 'Second');
        $secondEvent = $this->eventOn($secondOrg);

        $this->buyDomain($first, $firstOrg, [
                'event_id' => $firstEvent->id,
                'domain_kind' => 'register',
                'domain_name' => 'kontes.id',
            ])
            ->assertStatus(201);

        // Nothing is attached yet — the name is not in `custom_domain` — so a
        // check that looked only at events would wave this through and both
        // organizers would have paid for one name.
        $this->buyDomain($second, $secondOrg, [
                'event_id' => $secondEvent->id,
                'domain_kind' => 'register',
                'domain_name' => 'kontes.id',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain_name');

        // Compare: a different name from the same organization goes through.
        $this->buyDomain($second, $secondOrg, [
                'event_id' => $secondEvent->id,
                'domain_kind' => 'register',
                'domain_name' => 'kontes-lain.id',
            ])
            ->assertStatus(201);
    }

    public function test_a_reserved_platform_label_cannot_be_bought_as_a_subdomain(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org);

        // `api.floevent.id` is served by the platform's own vhost. A second
        // server block for it would have nginx serving whichever it loaded
        // first — the API would start resolving to an event page.
        $this->buyDomain($owner, $org, [
                'event_id' => $event->id,
                'domain_kind' => 'subdomain',
                'domain_name' => 'api',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain_name');

        // Compare: an ordinary label is accepted. The refusal alone passes
        // against a build that refuses every subdomain.
        $this->buyDomain($owner, $org, [
                'event_id' => $event->id,
                'domain_kind' => 'subdomain',
                'domain_name' => 'liga-f',
            ])
            ->assertStatus(201);
    }

    public function test_buying_a_domain_for_another_organizations_event_is_refused(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner, 'Mine');

        $stranger = $this->orgFor(User::factory()->create(), 'Theirs');
        $theirEvent = $this->eventOn($stranger);

        $this->buyDomain($owner, $org, [
                'event_id' => $theirEvent->id,
                'domain_kind' => 'subdomain',
                'domain_name' => 'liga-g',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_id');

        $this->assertNull($theirEvent->refresh()->custom_domain);
    }

    // ---- bought with the plan, attached at publish --------------------------

    public function test_an_addon_bought_with_a_plan_is_attached_when_the_event_is_published(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $plan = $this->sellablePlan();

        $this->checkout($owner, $org, [
            'plan_id' => $plan->id,
            'domain_kind' => 'subdomain',
            'domain_name' => 'liga-h',
        ])->assertStatus(201);

        $event = Event::findOrFail($this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", [
                'name' => 'Liga H',
                'sport_type' => 'football',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-10',
                'categories' => [['name' => 'A', 'tournament_format' => 'league']],
            ])
            ->assertStatus(201)
            ->json('data.id'));

        // NOT attached yet, and that is the invariant: a new event is a draft, a
        // draft 404s publicly, and asking Let's Encrypt to certify a page nobody
        // can open burns quota shared by the whole platform. The order is bound
        // to the event, though — otherwise publishing would have nothing to find.
        $this->assertSame('draft', $event->status);
        $this->assertNull($event->custom_domain);
        $this->assertSame(
            $event->id,
            EventPlanOrder::whereNotNull('domain_kind')->firstOrFail()->domain_event_id,
        );

        // Publishing is what hands the name over. Compared against the state
        // above, because asserting only the end result passes against a build
        // that attaches at creation and spends the quota on a draft.
        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$event->id}/status", ['status' => 'open'])
            ->assertOk();

        $this->assertSame('liga-h.floevent.id', $event->refresh()->custom_domain);
    }

    // ---- white label --------------------------------------------------------

    public function test_a_certified_domain_unlists_the_event_and_drops_the_platform_link(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $plain = $this->eventOn($org, null, ['name' => 'Tetap Terdaftar']);
        $labelled = $this->eventOn($org, null, ['name' => 'Putih']);

        // Assigned AND certified. Merely assigned is not enough: without a
        // certificate the hostname serves a TLS error, so unlisting it would
        // make the event unreachable from everywhere at once.
        $labelled->forceFill([
            'custom_domain' => 'putih.test',
            'domain_verified_at' => now(),
            'domain_certified_at' => now(),
        ])->save();

        $catalogue = $this->getJson('/api/v1/public/events')->assertOk();
        $names = array_column($catalogue->json('data.items'), 'name');

        // Compared, because a filter that dropped everything would pass a bare
        // "the white-labelled one is absent", and so would an empty catalogue.
        $this->assertContains('Tetap Terdaftar', $names);
        $this->assertNotContains('Putih', $names);
        // `total` has to agree with the page: filtering in PHP after pagination
        // would leave it counting a row the page does not carry.
        $this->assertSame(1, $catalogue->json('data.meta.total'));

        // And the flag the page branches on, published rather than derived from
        // the hostname a visitor arrived at — the /{org}/{event} URL still
        // resolves, and it must be white-labelled there too.
        $this->getJson("/api/v1/public/events/{$org->slug}/{$labelled->slug}")
            ->assertOk()
            ->assertJsonPath('data.white_label', true);

        $this->getJson("/api/v1/public/events/{$org->slug}/{$plain->slug}")
            ->assertOk()
            ->assertJsonPath('data.white_label', false);
    }

    public function test_an_uncertified_domain_leaves_the_event_listed(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org, null, ['name' => 'Menunggu Sertifikat']);

        // The halfway state, and the one a `custom_domain IS NOT NULL` filter
        // would get wrong: the name is assigned but nothing serves it yet.
        $event->forceFill(['custom_domain' => 'menunggu.test', 'domain_verified_at' => now()])->save();

        $names = array_column(
            $this->getJson('/api/v1/public/events')->assertOk()->json('data.items'),
            'name',
        );

        $this->assertContains('Menunggu Sertifikat', $names);
        $this->assertFalse($event->refresh()->isWhiteLabel());
    }

    // ---- the price list -----------------------------------------------------

    public function test_the_public_catalogue_quotes_what_the_till_charges(): void
    {
        $response = $this->getJson('/api/v1/domain-addons')->assertOk();

        $this->assertSame(100_000.0, (float) $response->json('data.setup_fee'));
        // Each TLD's `total` is registration + setup, sent rather than left for
        // the client to add up — the figure on the card and the figure at the
        // till come from one place.
        $totals = collect($response->json('data.tlds'))->pluck('total', 'tld');
        $this->assertSame(400_000.0, (float) $totals['com']);
        $this->assertSame(470_000.0, (float) $totals['id']);

        // A super admin raising a price moves the quote with no deploy, which is
        // the reason these are settings and not config literals.
        PlatformSettings::put(['domain_register_id_amount' => 400_000]);

        $this->assertSame(
            500_000.0,
            (float) collect($this->getJson('/api/v1/domain-addons')->json('data.tlds'))
                ->firstWhere('tld', 'id')['total'],
        );
    }

    public function test_the_price_preview_refuses_what_the_checkout_would_refuse(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org);

        $event->forceFill([
            'custom_domain' => 'diambil.floevent.id',
            'subdomain' => 'diambil',
            'domain_certified_at' => now(),
        ])->save();

        $stranger = User::factory()->create();
        $strangerOrg = $this->orgFor($stranger, 'Lain');

        // The whole reason the preview shares its resolver with the till: a code
        // path that priced a name here and refused it at the checkout means
        // paying first and finding out second.
        $this->actingAs($stranger, 'api')
            ->postJson("/api/v1/organizations/{$strangerOrg->id}/plan-orders/domain-preview", [
                'domain_kind' => 'subdomain',
                'domain_name' => 'diambil',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain_name');

        // Compare: a free label previews, and comes back as the full hostname it
        // will be stored as.
        $this->actingAs($stranger, 'api')
            ->postJson("/api/v1/organizations/{$strangerOrg->id}/plan-orders/domain-preview", [
                'domain_kind' => 'subdomain',
                'domain_name' => 'Belum Diambil',
            ])
            ->assertOk()
            ->assertJsonPath('data.domain_name', 'belum-diambil.floevent.id')
            // Compared loosely on purpose: JSON carries 100000, and asserting
            // the PHP float would be testing the encoder.
            ->assertJsonPath('data.amount', fn ($amount) => (float) $amount === 100_000.0);
    }

    // ---- releasing ----------------------------------------------------------

    public function test_releasing_a_subdomain_returns_its_label_to_the_pool(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org);

        $event->forceFill([
            'custom_domain' => 'dilepas.floevent.id',
            'subdomain' => 'dilepas',
            'domain_certified_at' => now(),
        ])->save();

        $this->actingAs($this->superAdmin(), 'api')
            ->deleteJson("/api/v1/admin/events/{$event->id}/domain")
            ->assertOk();

        $event->refresh();

        // Both columns, because clearing only the hostname would leave a label
        // claiming we still hold the name — and the next buyer of `dilepas`
        // would collide with a row that is no longer serving anything.
        $this->assertNull($event->custom_domain);
        $this->assertNull($event->subdomain);
        $this->assertFalse($event->isWhiteLabel());
    }

    public function test_assigning_an_outside_domain_clears_a_subdomain_label(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->eventOn($org);

        $event->forceFill(['custom_domain' => 'lama.floevent.id', 'subdomain' => 'lama'])->save();

        $this->actingAs($this->superAdmin(), 'api')
            ->putJson("/api/v1/admin/events/{$event->id}/domain", ['custom_domain' => 'baru.test'])
            ->assertOk();

        $event->refresh();

        // The stale label is the thing under test: left behind, it would claim
        // we issued `baru.test` under our own zone, which is the one fact the
        // column exists to distinguish.
        $this->assertSame('baru.test', $event->custom_domain);
        $this->assertNull($event->subdomain);
    }
}
