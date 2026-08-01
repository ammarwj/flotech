<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\EventPlanOrder;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Paying for a plan while the payment gateway is switched off.
 *
 * The sibling file, ManualPaymentTest, covers money flowing *to* an organizer:
 * a buyer transfers to their account and an org admin approves. This one covers
 * money flowing the other way — an organizer transfers to flo-event's own
 * account and a super admin approves — which is why none of it is reachable
 * from PaymentRails::destinationFor().
 */
class ManualPlanOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSettings::flush();
    }

    /**
     * The switch is cached `rememberForever` behind a static memo, so writing
     * the row alone changes nothing — the flush is the point.
     */
    private function gateway(bool $enabled): void
    {
        PlatformSettings::put(['payment_gateway_enabled' => $enabled], null);
        PlatformSettings::flush();
    }

    private function platformAccount(): void
    {
        SiteSetting::create([
            'bank_name' => 'BCA',
            'bank_code' => '014',
            'account_number' => '9998887777',
            'account_holder' => 'PT Flo Event Indonesia',
        ]);
    }

    private function plan(): Plan
    {
        return Plan::create([
            'name' => 'Pro',
            'slug' => 'pro-'.uniqid(),
            "price" => 399000,
                    ]);
    }

    /** A brand new organization: no plan, and therefore no entitlements at all. */
    private function org(User $owner): Organization
    {
        return Organization::create([
            'name' => 'Org',
            'slug' => 'org-'.uniqid(),
            'owner_id' => $owner->id,
            'contact_email' => 'org@example.test',
        ]);
    }

    /** @return array<string, mixed> the checkout response payload */
    private function checkout(User $user, Organization $org, Plan $plan, int $status = 201): array
    {
        return $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/checkout", [
                'plan_id' => $plan->id,
            ])
            ->assertStatus($status)
            ->json('data');
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /**
     * The whole feature, stated as one test.
     *
     * Asserting "the manual checkout came back manual" proves nothing on its
     * own — a build where the branch never runs would still have to produce
     * *something*. The two rails have to be compared on the same org and the
     * same plan: one reaches Midtrans (and, unconfigured under test, settles on
     * the spot), the other must not settle at all.
     */
    public function test_checkout_takes_the_gateway_or_the_platform_account_depending_on_the_switch(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();
        $this->platformAccount();

        // Rail 1 — gateway on. MIDTRANS_SERVER_KEY is blank under test, so the
        // mock settles it immediately and the organizer is left holding a credit.
        $this->gateway(true);
        $gatewayOrg = $this->org($user);
        $viaGateway = $this->checkout($user, $gatewayOrg, $plan);

        $this->assertSame('gateway', $viaGateway['payment_method']);
        $this->assertNull($viaGateway['bank_account']);
        $this->assertSame('paid', $viaGateway["plan_order"]['status']);
        // Paid, and waiting on an event rather than on a clock.
        $this->assertNull($viaGateway["plan_order"]['event_id']);
        $this->assertSame(1, $gatewayOrg->planOrders()->unconsumed()->count());

        // Rail 2 — gateway off. Same plan, same price, same user.
        $this->gateway(false);
        $manualOrg = $this->org($user);
        $viaManual = $this->checkout($user, $manualOrg, $plan);

        $this->assertSame('manual', $viaManual['payment_method']);
        $this->assertSame('9998887777', $viaManual['bank_account']['account_number']);
        $this->assertSame('past_due', $viaManual["plan_order"]['status']);
        // `mock` means "no server key", never "paid" — a manual checkout must
        // not borrow that flag on its way past the auto-activate branch.
        $this->assertFalse($viaManual['mock']);
        $this->assertNull($viaManual["plan_order"]['receipt_number']);
        // No credit until a human says the money arrived.
        $this->assertSame(0, $manualOrg->planOrders()->unconsumed()->count());
    }

    /**
     * A refused checkout must not burn an invoice number: nextNumber() has no
     * way to hand one back, so the rail has to be settled before the row is
     * created. Reverse those two statements and this test fails on the count.
     */
    public function test_manual_checkout_without_a_platform_account_issues_no_invoice_number(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $plan = $this->plan();

        $this->gateway(false); // no site_settings row at all

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/checkout", [
                'plan_id' => $plan->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount("event_plan_orders", 0);
    }

    /**
     * The two destinations must not be collapsed into one method. An organizer
     * with a payout account of their own still pays *us* into *our* account.
     */
    public function test_manual_checkout_does_not_use_the_organizers_own_account(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $plan = $this->plan();
        $this->platformAccount();

        $org->bankAccounts()->create([
            'bank_name' => 'Mandiri',
            'bank_code' => '008',
            'account_number' => '1111111111',
            'account_holder' => 'Jakarta Sports EO',
            'is_primary' => true,
        ]);

        $this->gateway(false);
        $data = $this->checkout($user, $org, $plan);

        $this->assertSame('9998887777', $data['bank_account']['account_number']);
        $this->assertSame('PT Flo Event Indonesia', $data['bank_account']['account_holder']);
    }

    /**
     * Buying the first plan is the one flow that has to work without a plan.
     * PlanGate grants a planless org nothing, so asking platformDestination()
     * for the `payment_gateway` entitlement would refuse every new signup.
     */
    public function test_org_without_a_plan_can_still_pay_manually(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $plan = $this->plan();
        $this->platformAccount();

        $this->assertNull($org->plan_id);

        $this->gateway(false);
        $data = $this->checkout($user, $org, $plan);

        $this->assertSame('manual', $data['payment_method']);
    }

    public function test_proof_upload_then_approve_activates_the_plan_once(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $plan = $this->plan();
        $this->platformAccount();
        $this->gateway(false);

        $sub = $this->checkout($user, $org, $plan)["plan_order"];

        $uploaded = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$sub['id']}/proof", [
                'payment_proof_url' => 'https://cdn.test/proof.jpg',
            ])
            ->assertOk()
            ->json('data');

        $this->assertTrue($uploaded['awaiting_verification']);
        $this->assertSame('past_due', $uploaded['status']);
        $this->assertNull($org->fresh()->plan_id, 'a proof is not a payment');

        $admin = $this->superAdmin();

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/plan-orders')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $approved = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/plan-orders/{$sub['id']}/approve")
            ->assertOk()
            ->json('data');

        $this->assertSame('paid', $approved['status']);
        $this->assertNotNull($approved['receipt_number']);
        // The money is in; what the organizer holds is one unspent credit.
        $this->assertSame(1, $org->planOrders()->unconsumed()->count());

        // A second approval must not mint a second receipt.
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/plan-orders/{$sub['id']}/approve")
            ->assertStatus(422);

        $this->assertSame(
            $approved['receipt_number'],
            EventPlanOrder::find($sub['id'])->receipt_number,
        );
    }

    public function test_proof_can_be_rejected_then_re_uploaded_and_approved(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $plan = $this->plan();
        $this->platformAccount();
        $this->gateway(false);

        $sub = $this->checkout($user, $org, $plan)["plan_order"];
        $proofUrl = "/api/v1/organizations/{$org->id}/plan-orders/{$sub['id']}/proof";

        $this->actingAs($user, 'api')
            ->postJson($proofUrl, ['payment_proof_url' => 'https://cdn.test/wrong.jpg'])
            ->assertOk();

        $admin = $this->superAdmin();

        $rejected = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/plan-orders/{$sub['id']}/reject", [
                'reason' => 'Nominal tidak cocok.',
            ])
            ->assertOk()
            ->json('data');

        $this->assertFalse($rejected['awaiting_verification']);
        $this->assertSame('Nominal tidak cocok.', $rejected['rejected_reason']);
        // Never a verdict on the payment, only on this receipt — leaving
        // verified_at null is what lets a replacement re-enter the queue.
        $this->assertNull($rejected['verified_at']);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/plan-orders')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($user, 'api')
            ->postJson($proofUrl, ['payment_proof_url' => 'https://cdn.test/right.jpg'])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/plan-orders/{$sub['id']}/approve")
            ->assertOk();

        // Approving pays the bill; it does not hand out an entitlement. What the
        // organizer gets is a credit sitting unspent until an event claims it.
        $this->assertDatabaseHas('event_plan_orders', [
            'id' => $sub['id'],
            'plan_id' => $plan->id,
            'status' => 'paid',
            'event_id' => null,
        ]);
    }

    /**
     * Two guards, two directions: an operator may not commit the org to a bill,
     * and the org's own owner may not sign off on money paid to the platform.
     */
    public function test_operator_cannot_upload_proof_and_org_admin_cannot_approve(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $plan = $this->plan();
        $this->platformAccount();
        $this->gateway(false);

        $sub = $this->checkout($owner, $org, $plan)["plan_order"];
        $proofUrl = "/api/v1/organizations/{$org->id}/plan-orders/{$sub['id']}/proof";

        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $this->actingAs($operator, 'api')
            ->postJson($proofUrl, ['payment_proof_url' => 'https://cdn.test/proof.jpg'])
            ->assertStatus(403);

        // The owner may — that is what makes the 403 above about the role.
        $this->actingAs($owner, 'api')
            ->postJson($proofUrl, ['payment_proof_url' => 'https://cdn.test/proof.jpg'])
            ->assertOk();

        // …but approving is ours, not theirs.
        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/admin/plan-orders/{$sub['id']}/approve")
            ->assertStatus(403);

        $this->actingAs($this->superAdmin(), 'api')
            ->postJson("/api/v1/admin/plan-orders/{$sub['id']}/approve")
            ->assertOk();
    }

    /**
     * A bill with a receipt attached is waiting on us, not on the organizer.
     * Cancelling it would drop it out of the queue while still matching the
     * `status != 'active'` half of scopeAwaitingVerification().
     */
    public function test_expire_manual_sweeps_only_subscriptions_with_no_proof(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();
        $this->platformAccount();
        $this->gateway(false);

        $abandonedOrg = $this->org($user);
        $abandoned = $this->checkout($user, $abandonedOrg, $plan)["plan_order"];

        $paidOrg = $this->org($user);
        $paid = $this->checkout($user, $paidOrg, $plan)["plan_order"];

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$paidOrg->id}/plan-orders/{$paid['id']}/proof", [
                'payment_proof_url' => 'https://cdn.test/proof.jpg',
            ])
            ->assertOk();

        // Both are past their deadline; only one has anything to show for it.
        EventPlanOrder::query()->update(['payment_deadline_at' => Carbon::now()->subHour()]);

        $this->artisan('plan-orders:expire-manual')->assertSuccessful();

        $this->assertSame('cancelled', EventPlanOrder::find($abandoned['id'])->status);
        $this->assertSame('past_due', EventPlanOrder::find($paid['id'])->status);

        $this->actingAs($this->superAdmin(), 'api')
            ->getJson('/api/v1/admin/plan-orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $paid['id']);
    }

    /** A cancelled invoice was still issued and emailed; its number is spent. */
    public function test_a_cancelled_manual_invoice_does_not_recycle_its_number(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();
        $this->platformAccount();
        $this->gateway(false);

        $first = $this->checkout($user, $this->org($user), $plan)["plan_order"];
        $this->assertStringEndsWith('/0001', $first['invoice_number']);

        EventPlanOrder::query()->update(['payment_deadline_at' => Carbon::now()->subHour()]);
        $this->artisan('plan-orders:expire-manual')->assertSuccessful();

        $second = $this->checkout($user, $this->org($user), $plan)["plan_order"];
        $this->assertStringEndsWith('/0002', $second['invoice_number']);
    }
}
