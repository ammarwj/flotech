<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionActivated;
use App\Services\BillingDocumentService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function plan(string $slug = 'pro'): Plan
    {
        return Plan::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'price_monthly' => 399000,
            'price_yearly' => 3830000,
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
            ->postJson("/api/v1/organizations/{$org->id}/subscriptions/checkout", [
                'plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
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

        $this->assertSame('active', $data['subscription']['status']);
        $this->assertMatchesRegularExpression('#^INV/\d{4}/\d{2}/0001$#', $data['subscription']['invoice_number']);
        $this->assertMatchesRegularExpression('#^KW/\d{4}/\d{2}/0001$#', $data['subscription']['receipt_number']);
    }

    public function test_invoice_numbers_are_sequential_and_unique(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $plan = $this->plan();

        $first = $this->checkout($user, $org, $plan)['subscription'];
        $second = $this->checkout($user, $org, $plan)['subscription'];

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

        $id = $this->checkout($user, $org, $plan)['subscription']['id'];
        $subscription = Subscription::findOrFail($id);
        $receipt = $subscription->receipt_number;
        $paidAt = $subscription->paid_at;

        app(SubscriptionService::class)->activate($subscription->fresh(), 'bank_transfer');

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

        $id = $this->checkout($user, $org, $plan)['subscription']['id'];
        $subscription = Subscription::findOrFail($id);

        $mail = (new SubscriptionActivated($subscription))->toMail($user);
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

        $paid = Subscription::findOrFail($this->checkout($user, $org, $plan)['subscription']['id']);

        $this->actingAs($user, 'api')
            ->get("/api/v1/organizations/{$org->id}/subscriptions/{$paid->id}/invoice")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($user, 'api')
            ->get("/api/v1/organizations/{$org->id}/subscriptions/{$paid->id}/receipt")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $unpaid = $org->subscriptions()->create([
            'plan_id' => $plan->id,
            'invoice_number' => 'INV/2026/01/0099',
            'billing_cycle' => 'monthly',
            'amount' => 399000,
            'status' => 'past_due',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/subscriptions/{$unpaid->id}/receipt")
            ->assertStatus(403);
    }

    public function test_subscription_from_another_org_is_not_reachable(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $plan = $this->plan();
        $sub = Subscription::findOrFail($this->checkout($user, $org, $plan)['subscription']['id']);

        $other = $this->org($user, 'org-other');

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$other->id}/subscriptions/{$sub->id}/invoice")
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
            ->getJson("/api/v1/organizations/{$org->id}/subscriptions")
            ->assertStatus(403);

        $this->actingAs($operator, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/subscriptions/checkout", [
                'plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
            ])
            ->assertStatus(403);
    }
}
