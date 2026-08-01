<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\EventPlanOrder;
use App\Models\User;
use App\Notifications\PlanOrderPaid;
use App\Services\BillingDocumentService;
use App\Services\PlatformSettings;
use App\Services\EventPlanOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanOrderBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function plan(string $slug = 'pro'): Plan
    {
        return Plan::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            "price" => 399000,
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
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$sub->id}/pay")
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
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$sub->id}/pay")
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
}
