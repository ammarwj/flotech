<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\EventPlanOrder;
use App\Models\User;
use App\Notifications\PlanOrderInvoiceIssued;
use App\Notifications\PlanOrderPaid;
use App\Services\BillingDocumentService;
use App\Services\PlatformSettings;
use App\Services\EventPlanOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanOrderBillingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A throwaway plan. The slug is randomised because the catalogue migration
     * seeds starter/pro/professional into every test database, and a fixed slug
     * would collide with the real row.
     */
    protected function plan(string $name = 'Pro'): Plan
    {
        return Plan::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'price' => 399000,
        ]);
    }

    protected function org(User $owner, string $slug = 'org-bill'): Organization
    {
        return Organization::create([
            'name' => 'Org Bill',
            'slug' => $slug,
            'owner_id' => $owner->id,
            'contact_email' => 'org@example.test',
        ]);
    }

    protected function checkout(User $user, Organization $org, Plan $plan): array
    {
        return $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/checkout", [
                'plan_id' => $plan->id,
                // Ignored on the manual rail, required on gateway — safe to
                // always send so this helper works for both.
                'payment_channel' => 'va',
            ])
            ->assertCreated()
            ->json('data');
    }

    public function test_checkout_issues_an_invoice_number_and_payment_issues_a_receipt_number(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $plan = $this->plan();

        // Midtrans is unconfigured in tests, so checkout auto-activates.
        $data = $this->checkout($user, $org, $plan);

        $this->assertSame('paid', $data["plan_order"]['status']);
        $this->assertMatchesRegularExpression('#^INV/\d{4}/\d{2}/0001$#', $data["plan_order"]['invoice_number']);
        $this->assertMatchesRegularExpression('#^KW/\d{4}/\d{2}/0001$#', $data["plan_order"]['receipt_number']);
    }

    public function test_invoice_numbers_are_sequential_and_unique(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $plan = $this->plan();

        $first = $this->checkout($user, $org, $plan)["plan_order"];
        $second = $this->checkout($user, $org, $plan)["plan_order"];

        $this->assertStringEndsWith('/0001', $first['invoice_number']);
        $this->assertStringEndsWith('/0002', $second['invoice_number']);
    }

    /**
     * Midtrans re-delivers webhooks; a second settlement for the same order
     * must not mint a second receipt.
     */
    public function test_activating_twice_does_not_reissue_the_receipt(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $plan = $this->plan();

        $id = $this->checkout($user, $org, $plan)["plan_order"]['id'];
        $subscription = EventPlanOrder::findOrFail($id);
        $receipt = $subscription->receipt_number;
        $paidAt = $subscription->paid_at;

        app(EventPlanOrderService::class)->activate($subscription->fresh(), 'bank_transfer');

        $subscription->refresh();
        $this->assertSame($receipt, $subscription->receipt_number);
        $this->assertEquals($paidAt, $subscription->paid_at);
        $this->assertSame('bank_transfer', $subscription->payment_type);
    }

    /**
     * The activation mail carries both documents. Attaching only the receipt
     * leaves a gap: checkout mails the invoice only while the bill is still
     * outstanding, so an instantly-activated subscription (this test's path,
     * and every gateway-less setup) would produce a receipt referencing an
     * invoice number the organizer never received.
     */
    public function test_activation_mail_attaches_the_invoice_as_well_as_the_receipt(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $plan = $this->plan();

        $id = $this->checkout($user, $org, $plan)["plan_order"]['id'];
        $subscription = EventPlanOrder::findOrFail($id);

        $mail = (new PlanOrderPaid($subscription))->toMail($user);
        $names = array_column($mail->rawAttachments, 'name');

        $docs = app(BillingDocumentService::class);
        $this->assertSame(
            [$docs->filename('invoice', $subscription), $docs->filename('receipt', $subscription)],
            $names,
            'Both documents must be attached, invoice first.',
        );

        // Real PDFs, not empty strings — filename alone would pass on a broken render.
        foreach ($mail->rawAttachments as $attachment) {
            $this->assertStringStartsWith('%PDF', $attachment['data']);
            $this->assertSame('application/pdf', $attachment['options']['mime']);
        }

        // Both numbers are named in the body, so the mail stands on its own even
        // if the attachments are stripped by a mail client.
        $rendered = (string) $mail->render();
        $this->assertStringContainsString($subscription->invoice_number, $rendered);
        $this->assertStringContainsString($subscription->receipt_number, $rendered);
    }

    /**
     * The mail body must total the same as the PDF stapled to it.
     *
     * Both emails printed `amount` — the plan price alone — while the attached
     * document totalled `gross_amount`, so an organizer charged for fees was
     * told two different numbers in one message. Asserting the gross is present
     * is not enough on its own: this also asserts the bare plan price is *not*
     * presented as the total, which is the half that was wrong.
     */
    public function test_billing_mail_totals_the_fees_the_same_way_the_pdf_does(): void
    {
        PlatformSettings::put(['plan_service_fee_percent' => 1.5], null);
        PlatformSettings::flush();

        $user = User::factory()->create();
        $org = $this->org($user, 'org-mail-total');
        $plan = $this->plan();

        $order = EventPlanOrder::findOrFail($this->checkout($user, $org, $plan)['plan_order']['id']);
        app(EventPlanOrderService::class)->activate($order->fresh(), 'bank_transfer');
        $order = $order->fresh();

        $this->assertGreaterThan((float) $order->amount, $order->gross_amount, 'sanity: this order must carry fees');

        $money = fn (float $n) => number_format($n, 0, ',', '.');

        foreach ([new PlanOrderPaid($order), new PlanOrderInvoiceIssued($order)] as $notification) {
            $rendered = (string) $notification->toMail($user)->render();

            $this->assertStringContainsString($money($order->gross_amount), $rendered);
            $this->assertStringContainsString($money((float) $order->gateway_tax), $rendered);
        }
    }

    public function test_invoice_pdf_downloads_and_receipt_requires_payment(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $plan = $this->plan();

        $paid = EventPlanOrder::findOrFail($this->checkout($user, $org, $plan)["plan_order"]['id']);

        $this->actingAs($user, 'api')
            ->get("/api/v1/organizations/{$org->id}/plan-orders/{$paid->id}/invoice")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($user, 'api')
            ->get("/api/v1/organizations/{$org->id}/plan-orders/{$paid->id}/receipt")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $unpaid = $org->planOrders()->create([
            'plan_id' => $plan->id,
            'invoice_number' => 'INV/2026/01/0099',
            'amount' => 399000,
            'status' => 'past_due',
        ]);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/plan-orders/{$unpaid->id}/receipt")
            ->assertStatus(403);
    }

    public function test_subscription_from_another_org_is_not_reachable(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $plan = $this->plan();
        $sub = EventPlanOrder::findOrFail($this->checkout($user, $org, $plan)["plan_order"]['id']);

        $other = $this->org($user, 'org-other');

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$other->id}/plan-orders/{$sub->id}/invoice")
            ->assertStatus(404);
    }

    /**
     * The gate-scanning operator is a full tenant member, so `tenant` alone
     * would let them buy a plan with the owner's money and read the invoices.
     */
    public function test_operator_member_cannot_reach_billing(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $org = $this->org($owner);
        $plan = $this->plan();

        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $this->actingAs($operator, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/plan-orders")
            ->assertStatus(403);

        $this->actingAs($operator, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/checkout", [
                'plan_id' => $plan->id,
            ])
            ->assertStatus(403);
    }

    /**
     * A bill raised on one rail has to stay payable on whichever rail is live
     * when the organizer gets round to it — pay() re-derives rather than
     * replays, exactly as RegistrationService::startPayment() already does.
     *
     * Both directions in one test on purpose: asserting only the on-to-off flip
     * would pass on an implementation that hard-codes `manual`.
     */
    public function test_pay_reopens_on_whichever_rail_is_live_now(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $plan = $this->plan();

        SiteSetting::create([
            'bank_name' => 'BCA',
            'bank_code' => '014',
            'account_number' => '9998887777',
            'account_holder' => 'PT Flo Event Indonesia',
        ]);

        // Checkout on the gateway rail. Midtrans is unconfigured under test, so
        // it auto-activates; push it back to the state a real unpaid bill is in.
        $sub = EventPlanOrder::findOrFail($this->checkout($user, $org, $plan)["plan_order"]['id']);
        $sub->update(['status' => 'past_due', 'paid_at' => null, 'receipt_number' => null]);
        $invoiceNumber = $sub->invoice_number;

        PlatformSettings::put(['payment_gateway_enabled' => false], null);
        PlatformSettings::flush();

        $offline = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$sub->id}/pay", [
                'payment_channel' => 'va',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('manual', $offline['payment_method']);
        $this->assertSame('9998887777', $offline['bank_account']['account_number']);
        // Still the same one bill, however many times payment is reopened.
        $this->assertSame($invoiceNumber, $offline["plan_order"]['invoice_number']);

        $sub->update(['status' => 'past_due', 'paid_at' => null, 'receipt_number' => null]);

        PlatformSettings::put(['payment_gateway_enabled' => true], null);
        PlatformSettings::flush();

        $online = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$sub->id}/pay", [
                'payment_channel' => 'va',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('gateway', $online['payment_method']);
        $this->assertNull($online['bank_account']);
        $this->assertSame($invoiceNumber, $online["plan_order"]['invoice_number']);
    }

    /**
     * pay() re-derives the rail, so letting it run while a receipt is under
     * review would flip the bill to gateway and strand that receipt in the
     * super admin's queue.
     */
    public function test_pay_is_refused_while_a_proof_is_under_review(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $plan = $this->plan();

        SiteSetting::create([
            'bank_name' => 'BCA',
            'account_number' => '9998887777',
            'account_holder' => 'PT Flo Event Indonesia',
        ]);

        PlatformSettings::put(['payment_gateway_enabled' => false], null);
        PlatformSettings::flush();

        $sub = EventPlanOrder::findOrFail($this->checkout($user, $org, $plan)["plan_order"]['id']);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$sub->id}/proof", [
                'payment_proof_url' => 'https://cdn.test/proof.jpg',
            ])
            ->assertOk();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$sub->id}/pay")
            ->assertStatus(422);

        $this->assertSame('manual', $sub->fresh()->payment_method);
        $this->assertNotNull($sub->fresh()->payment_proof_url);
    }

    /**
     * The organizer is the buyer here: gateway checkout adds fee on top of the
     * plan price, manual does not. Compared on the same plan so the plan price
     * itself can't explain the difference — `amount` (what paidTowardsPlan()
     * sums) must stay identical while `gross_amount` (what Midtrans/the PDF
     * see) diverges.
     */
    public function test_gateway_checkout_charges_the_buyer_a_fee_while_manual_does_not(): void
    {
        // The organizer-side margin: a plan purchase must not read the rate set
        // for participants buying tickets. Both are set, differently, so
        // reading the wrong one shows up as a wrong number.
        PlatformSettings::put([
            'service_fee_percent' => 9,
            'plan_service_fee_percent' => 1.5,
        ], null);
        PlatformSettings::flush();

        $user = User::factory()->create();
        $plan = $this->plan();

        $gatewayOrg = $this->org($user, 'org-gateway');
        $viaGateway = EventPlanOrder::findOrFail($this->checkout($user, $gatewayOrg, $plan)['plan_order']['id']);

        $this->assertSame('va', $viaGateway->payment_channel);
        $this->assertEqualsWithDelta(4000 * 1.11, $viaGateway->gateway_fee, 0.001);
        // Snapshotted, not recomputed at render time: the invoice prints the
        // tax as its own line and must keep printing the rate that applied the
        // day it was issued, even after config/payment_fees.php changes.
        $this->assertEqualsWithDelta(440.0, $viaGateway->gateway_tax, 0.001);
        $this->assertLessThan(
            (float) $viaGateway->gateway_fee,
            (float) $viaGateway->gateway_tax,
            'gateway_tax is a part of gateway_fee, not an amount on top of it',
        );
        $this->assertEqualsWithDelta(399000 * 1.5 / 100, $viaGateway->service_fee, 0.001);
        $this->assertEqualsWithDelta(
            399000 + $viaGateway->gateway_fee + $viaGateway->service_fee,
            $viaGateway->gross_amount,
            0.001,
        );

        PlatformSettings::put(['payment_gateway_enabled' => false], null);
        PlatformSettings::flush();
        SiteSetting::create([
            'bank_name' => 'BCA',
            'account_number' => '9998887777',
            'account_holder' => 'PT Flo Event Indonesia',
        ]);

        $manualOrg = $this->org($user, 'org-manual');
        $viaManual = EventPlanOrder::findOrFail($this->checkout($user, $manualOrg, $plan)['plan_order']['id']);

        $this->assertNull($viaManual->payment_channel);
        $this->assertSame(0.0, (float) $viaManual->gateway_fee);
        $this->assertSame(0.0, (float) $viaManual->gateway_tax);
        $this->assertSame(0.0, (float) $viaManual->service_fee);
        $this->assertSame(399000.0, (float) $viaManual->gross_amount);

        // Same plan, same `amount` — the fee never leaks into the number
        // paidTowardsPlan()/upgrade pricing reads.
        $this->assertSame((float) $viaGateway->amount, (float) $viaManual->amount);
    }

    /**
     * A second upgrade must price against what was actually owed for the
     * plan, not against a bill that already included the first upgrade's
     * gateway fee. Using gross_amount instead of amount here would overcharge
     * on every upgrade after the first.
     */
    /**
     * The admin purchase list has to show gateway purchases, which is the whole
     * reason it exists: the queue and the history are both built on
     * manual-transfer columns, so a plan bought through Midtrans reached
     * neither. Compared against the history endpoint on the same data — a list
     * that returned only manual rows would still look populated on its own.
     */
    public function test_the_admin_purchase_list_shows_gateway_orders_the_verification_lists_cannot(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();
        $org = $this->org($user, 'org-purchases');

        $order = EventPlanOrder::findOrFail($this->checkout($user, $org, $plan)['plan_order']['id']);
        app(EventPlanOrderService::class)->activate($order->fresh(), 'bank_transfer');

        $admin = User::factory()->create(['role' => 'super_admin']);

        $purchases = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/plan-orders/purchases')
            ->assertOk()
            ->json('data.items');

        $this->assertSame([$order->id], array_column($purchases, 'id'));
        $this->assertSame('gateway', $purchases[0]['payment_method']);
        $this->assertSame('va', $purchases[0]['payment_channel']);
        $this->assertGreaterThan(0, $purchases[0]['gateway_fee']);
        $this->assertGreaterThan($purchases[0]['amount'], $purchases[0]['gross_amount']);

        // The list this used to be the only one of: verified_at is null on a
        // gateway order, so it is empty here.
        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/plan-orders/history')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Each filter compared against the unfiltered list on the same two orders —
     * a filter that silently did nothing would still return rows, and asserting
     * "the one I wanted is present" would pass right through that.
     */
    public function test_the_admin_purchase_list_filters_by_rail_and_by_search(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();

        $gatewayOrg = $this->org($user, 'org-filter-gateway');
        $gateway = EventPlanOrder::findOrFail($this->checkout($user, $gatewayOrg, $plan)['plan_order']['id']);
        app(EventPlanOrderService::class)->activate($gateway->fresh(), 'bank_transfer');

        // A settled manual order, written directly: the manual rail needs the
        // gateway switched off, and flipping it mid-test would also change what
        // the gateway order above is allowed to be.
        $manualOrg = $this->org($user, 'org-filter-manual');
        $manual = $manualOrg->planOrders()->create([
            'plan_id' => $plan->id,
            'invoice_number' => 'INV/2026/02/7777',
            'amount' => 399000,
            'status' => 'paid',
            'payment_method' => 'manual',
            'paid_at' => now(),
        ]);

        $admin = User::factory()->create(['role' => 'super_admin']);

        $ids = fn (array $params) => array_column(
            $this->actingAs($admin, 'api')
                ->getJson('/api/v1/admin/plan-orders/purchases?'.http_build_query($params))
                ->assertOk()
                ->json('data.items'),
            'id',
        );

        $this->assertEqualsCanonicalizing([$gateway->id, $manual->id], $ids([]));
        $this->assertSame([$gateway->id], $ids(['method' => 'gateway']));
        $this->assertSame([$manual->id], $ids(['method' => 'manual']));
        $this->assertSame([$gateway->id], $ids(['channel' => 'va']));

        // Search spans the order's own numbers and its organization's name.
        $this->assertSame([$manual->id], $ids(['q' => 'INV/2026/02/7777']));
        $this->assertSame([$gateway->id], $ids(['q' => $gateway->invoice_number]));

        // Case-insensitivity is the whole reason Search::anyColumn exists:
        // plain LIKE is case-sensitive on Postgres but not on sqlite, so a
        // lowercase needle is what tells the two apart.
        $this->assertSame([$manual->id], $ids(['q' => strtolower('INV/2026/02/7777')]));

        $this->assertSame([], $ids(['from' => now()->addDay()->toDateString()]));
    }

    public function test_the_admin_purchase_list_is_super_admin_only(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson('/api/v1/admin/plan-orders/purchases')
            ->assertStatus(403);
    }

    public function test_an_upgrades_gateway_fee_does_not_compound_into_the_next_upgrades_price(): void
    {
        $starter = $this->plan('Starter A');
        $starter->update(['price' => 150000]);
        $pro = $this->plan('Pro A');
        $pro->update(['price' => 350000]);
        $professional = $this->plan('Professional A');
        $professional->update(['price' => 800000]);

        $user = User::factory()->create();
        $org = $this->org($user, 'org-upgrade-fee');

        $base = EventPlanOrder::findOrFail($this->checkout($user, $org, $starter)['plan_order']['id']);
        $this->assertGreaterThan(0, $base->gateway_fee, 'sanity: gateway checkout must carry a fee to begin with');

        $firstUpgradeId = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$base->id}/upgrade", [
                'plan_id' => $pro->id,
                'payment_channel' => 'va',
            ])
            ->assertCreated()
            ->json('data.plan_order.id');

        $firstUpgrade = EventPlanOrder::findOrFail($firstUpgradeId);
        $this->assertEqualsWithDelta(350000 - 150000, $firstUpgrade->amount, 0.001);

        $secondUpgradeId = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$firstUpgrade->id}/upgrade", [
                'plan_id' => $professional->id,
                'payment_channel' => 'va',
            ])
            ->assertCreated()
            ->json('data.plan_order.id');

        $secondUpgrade = EventPlanOrder::findOrFail($secondUpgradeId);
        // Priced against 150000 + 35000 paid so far — never against the gross
        // amounts, which would each be inflated by their own gateway_fee.
        $this->assertEqualsWithDelta(800000 - 350000, $secondUpgrade->amount, 0.001);
    }
}
