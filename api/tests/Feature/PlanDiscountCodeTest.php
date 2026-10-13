<?php

namespace Tests\Feature;

use App\Models\DiscountCode;
use App\Models\EventPlanOrder;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\EventPlanOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Discount codes at plan checkout.
 *
 * Every case here *compares* two runs rather than asserting one, which is the
 * house rule: a single "the discount applied" assert passes just as happily on
 * a build that applies the same wrong number to everything.
 *
 * Midtrans is unconfigured under test, so a gateway checkout settles on the
 * spot — which is exactly what makes the quota and once-per-org tests readable:
 * the first purchase is genuinely `paid`.
 */
class PlanDiscountCodeTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function plan(string $name = 'Pro', float $price = 400000): Plan
    {
        $plan = Plan::create([
            'name' => $name,
            'slug' => 'disc-'.uniqid(),
            'price' => $price,
        ]);

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function code(array $attrs = []): DiscountCode
    {
        return DiscountCode::create([
            'code' => 'CODE'.strtoupper(bin2hex(random_bytes(3))),
            'discount_type' => 'percent',
            'discount_value' => 25,
            ...$attrs,
        ]);
    }

    /**
     * The checkout payload — or, on a refusal, the whole envelope so the caller
     * can read `errors`.
     *
     * @return array<string, mixed>
     */
    private function checkout(User $user, Organization $org, Plan $plan, ?string $code = null, int $status = 201, string $channel = 'va'): array
    {
        $response = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/checkout", [
                'plan_id' => $plan->id,
                'payment_channel' => $channel,
                'discount_code' => $code,
            ])
            ->assertStatus($status);

        return $status === 201 ? $response->json('data') : $response->json();
    }

    /**
     * JSON has no float type distinct from int, so a whole rupiah amount comes
     * back as `400000`, not `400000.0`. Every money assertion below reads
     * through this rather than littering casts.
     */
    private function money(mixed $value): float
    {
        return (float) $value;
    }

    /**
     * Both formulas, on the same plan at the same price — and the gateway fee
     * moving with them.
     *
     * The fee is the real assertion. It is a percentage of `amount`, so a
     * discount applied *after* resolvePayment() would still produce a cheaper
     * `amount` while charging a fee computed on the full price, and
     * `gross_amount` would quietly stop reconciling with what Midtrans was
     * asked for.
     */
    public function test_percent_and_fixed_both_discount_the_amount_and_the_fee_follows(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(price: 400000);

        // `ewallet`, not `va`: the VA fee is flat, so it is the same number
        // whatever the amount and could not tell a correct build from one that
        // discounts after the fee is computed. A percentage channel can.
        $full = $this->checkout($user, $this->orgFor($user), $plan, channel: 'ewallet')['plan_order'];
        $percent = $this->checkout($user, $this->orgFor($user), $plan, $this->code(['discount_value' => 25])->code, channel: 'ewallet')['plan_order'];
        $fixed = $this->checkout($user, $this->orgFor($user), $plan, $this->code([
            'discount_type' => 'fixed',
            'discount_value' => 50000,
        ])->code, channel: 'ewallet')['plan_order'];

        $this->assertSame(400000.0, $this->money($full['amount']));
        $this->assertSame(300000.0, $this->money($percent['amount']));
        $this->assertSame(350000.0, $this->money($fixed['amount']));

        $this->assertSame(100000.0, $this->money($percent['discount_amount']));
        $this->assertSame(50000.0, $this->money($fixed['discount_amount']));

        // Cheaper plan, cheaper fee. Equal fees would mean the discount landed
        // after the fee was computed.
        $this->assertLessThan($this->money($full['gateway_fee']), $this->money($percent['gateway_fee']));
        $this->assertLessThan($this->money($fixed['gateway_fee']), $this->money($percent['gateway_fee']));

        // And the buyer total stays internally consistent.
        $this->assertSame(
            round($this->money($percent['amount']) + $this->money($percent['gateway_fee']) + $this->money($percent['service_fee']), 2),
            round($this->money($percent['gross_amount']), 2),
        );
    }

    /** The ceiling only binds percent codes, and only when it is set. */
    public function test_max_discount_caps_a_percent_code(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(price: 400000);

        $uncapped = $this->checkout($user, $this->orgFor($user), $plan, $this->code(['discount_value' => 50])->code)['plan_order'];
        $capped = $this->checkout($user, $this->orgFor($user), $plan, $this->code([
            'discount_value' => 50,
            'max_discount' => 75000,
        ])->code)['plan_order'];

        $this->assertSame(200000.0, $this->money($uncapped['amount']));
        $this->assertSame(325000.0, $this->money($capped['amount']));
        $this->assertSame(75000.0, $this->money($capped['discount_amount']));
    }

    /**
     * A code worth the whole plan settles the order outright.
     *
     * Compared against a small code on the same plan: without the zero-total
     * branch in start(), the free order would be handed to openSnap() and
     * Midtrans asked for `gross_amount: 0`.
     */
    public function test_a_full_discount_settles_without_touching_the_gateway(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(price: 400000);

        $free = $this->checkout($user, $this->orgFor($user), $plan, $this->code([
            'discount_type' => 'fixed',
            // More than the plan: clamped to the price, not refunded.
            'discount_value' => 500000,
        ])->code)['plan_order'];

        $this->assertSame(0.0, $this->money($free['amount']));
        $this->assertSame(400000.0, $this->money($free['discount_amount']));
        $this->assertSame('paid', $free['status']);
        $this->assertNotNull($free['receipt_number']);
        // Never reached the gateway.
        $this->assertNull($free['midtrans_order_id']);
        $this->assertSame('discount', $free['payment_type']);

        // Still a credit waiting on an event — free does not mean pre-spent.
        $this->assertNull($free['event_id']);

        // The comparison: on the manual rail the same plan with a small code is
        // still owed money, so "settled" is not simply what this build does to
        // every discounted order.
        $small = $this->checkout($user, $this->orgFor($user), $plan, $this->code(['discount_value' => 10])->code)['plan_order'];
        $this->assertSame(360000.0, $this->money($small['amount']));
        $this->assertSame('paid', $small['status']); // mock gateway settles it
        $this->assertNotNull($small['midtrans_order_id']);
    }

    /**
     * The quota counts paid redemptions *and* outstanding invoices, and an
     * outstanding one frees up when it dies.
     *
     * Asserting only "the second checkout is refused" would pass on a build
     * that counts nothing but the row's existence. The release is the half that
     * proves usage is derived rather than a counter nobody decrements.
     */
    public function test_quota_counts_live_reservations_and_releases_cancelled_ones(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(price: 400000);
        $code = $this->code(['max_redemptions' => 1, 'once_per_organization' => false]);

        $first = $this->checkout($user, $this->orgFor($user), $plan, $code->code)['plan_order'];

        // Hold it open: this is the state a buyer sitting on a Midtrans page is in.
        EventPlanOrder::whereKey($first['id'])->update(['status' => 'past_due']);

        $refused = $this->checkout($user, $this->orgFor($user), $plan, $code->code, 422);
        $this->assertArrayHasKey('discount_code', $refused['errors']);

        // The webhook (or plan-orders:expire-manual) writes this; nothing has to
        // release the reservation explicitly.
        EventPlanOrder::whereKey($first['id'])->update(['status' => 'cancelled']);

        $second = $this->checkout($user, $this->orgFor($user), $plan, $code->code)['plan_order'];
        $this->assertSame(300000.0, $this->money($second['amount']));

        // And now the quota is genuinely spent.
        $this->checkout($user, $this->orgFor($user), $plan, $code->code, 422);
    }

    /** One allowance per organization — not per account, and not platform-wide. */
    public function test_once_per_organization_blocks_the_same_org_only(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(price: 400000);
        $code = $this->code(['once_per_organization' => true]);

        $orgA = $this->orgFor($user, 'A');
        $orgB = $this->orgFor($user, 'B');

        $this->checkout($user, $orgA, $plan, $code->code);
        $this->checkout($user, $orgA, $plan, $code->code, 422);

        // Same code, same owner, different organization: allowed.
        $this->assertSame(300000.0, $this->money($this->checkout($user, $orgB, $plan, $code->code)['plan_order']['amount']));
    }

    /**
     * An empty pivot means every plan — the common case, and the one a
     * "restricted to the plans listed" reading gets backwards.
     */
    public function test_plan_restriction_binds_only_when_the_pivot_has_rows(): void
    {
        $user = User::factory()->create();
        $pro = $this->plan('Pro', 400000);
        $professional = $this->plan('Professional', 800000);

        $restricted = $this->code(['once_per_organization' => false]);
        $restricted->plans()->sync([$pro->id]);
        $open = $this->code(['once_per_organization' => false]);

        $this->checkout($user, $this->orgFor($user), $professional, $restricted->code, 422);
        $this->assertSame(300000.0, $this->money($this->checkout($user, $this->orgFor($user), $pro, $restricted->code)['plan_order']['amount']));

        // No pivot rows at all: both plans accept it.
        $this->assertSame(300000.0, $this->money($this->checkout($user, $this->orgFor($user), $pro, $open->code)['plan_order']['amount']));
        $this->assertSame(600000.0, $this->money($this->checkout($user, $this->orgFor($user), $professional, $open->code)['plan_order']['amount']));
    }

    /** Three codes, one test: before the window, after it, and inside it. */
    public function test_the_validity_window_is_enforced_on_both_sides(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(price: 400000);

        $future = $this->code(['starts_at' => Carbon::now()->addDay()]);
        $past = $this->code(['ends_at' => Carbon::now()->subDay()]);
        $live = $this->code(['starts_at' => Carbon::now()->subDay(), 'ends_at' => Carbon::now()->addDay()]);
        $off = $this->code(['is_active' => false]);

        $this->checkout($user, $this->orgFor($user), $plan, $future->code, 422);
        $this->checkout($user, $this->orgFor($user), $plan, $past->code, 422);
        $this->checkout($user, $this->orgFor($user), $plan, $off->code, 422);
        $this->assertSame(300000.0, $this->money($this->checkout($user, $this->orgFor($user), $plan, $live->code)['plan_order']['amount']));
    }

    /** Codes are case-insensitive to the buyer. */
    public function test_a_code_matches_regardless_of_case(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(price: 400000);
        $code = $this->code(['once_per_organization' => false]);

        $upper = $this->checkout($user, $this->orgFor($user), $plan, $code->code)['plan_order'];
        $lower = $this->checkout($user, $this->orgFor($user), $plan, strtolower($code->code))['plan_order'];

        $this->assertSame($this->money($upper['amount']), $this->money($lower['amount']));
        $this->assertSame($code->code, $lower['discount_code']);
    }

    /**
     * The row is a snapshot, which is what makes editing and deleting a code
     * safe — and is why this controller has no "still in use" guard.
     */
    public function test_editing_or_deleting_a_code_leaves_issued_orders_untouched(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(price: 400000);
        $code = $this->code(['discount_value' => 25]);

        $order = $this->checkout($user, $this->orgFor($user), $plan, $code->code)['plan_order'];

        $code->update(['discount_value' => 90, 'code' => 'RENAMED']);

        $after = EventPlanOrder::find($order['id']);
        $this->assertSame(300000.0, (float) $after->amount);
        $this->assertSame(100000.0, (float) $after->discount_amount);
        $this->assertSame(25.0, (float) $after->discount_value);
        $this->assertSame($order['discount_code'], $after->discount_code);

        $code->delete();

        $after->refresh();
        // FK released, snapshot intact: the invoice still reads what was charged.
        $this->assertNull($after->discount_code_id);
        $this->assertSame($order['discount_code'], $after->discount_code);
        $this->assertSame(100000.0, (float) $after->discount_amount);
    }

    /**
     * A discount is made back on the first upgrade, by design.
     *
     * paidTowardsPlan() sums money actually received, so both chains end at the
     * target plan's price — the promise that method exists to keep, and the
     * reason the upgrade route takes no code of its own.
     */
    public function test_a_discount_does_not_carry_into_an_upgrade(): void
    {
        $user = User::factory()->create();
        $small = $this->plan('Kecil', 400000);
        $big = $this->plan('Besar', 800000);
        // The upgrade gate is a feature superset test, so the target has to
        // cover the source. Both empty: trivially a superset.
        $code = $this->code(['discount_type' => 'fixed', 'discount_value' => 50000, 'once_per_organization' => false]);

        foreach ([null, $code->code] as $used) {
            $org = $this->orgFor($user);
            $first = $this->checkout($user, $org, $small, $used)['plan_order'];

            $top = $this->actingAs($user, 'api')
                ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$first['id']}/upgrade", [
                    'plan_id' => $big->id,
                    'payment_channel' => 'va',
                ])
                ->assertCreated()
                ->json('data.plan_order');

            // Total spend lands on the target price either way; the discounted
            // chain simply pays more of it at the top-up.
            $this->assertSame(800000.0, round($this->money($first['amount']) + $this->money($top['amount']), 2));
        }
    }

    /**
     * The preview and the checkout are the same resolver, so a code accepted
     * there can never be refused at the till — and the number the channel
     * picker builds its fee preview from is the number that gets billed.
     */
    public function test_the_preview_returns_exactly_what_checkout_will_charge(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);
        $plan = $this->plan(price: 400000);
        $code = $this->code(['discount_value' => 25]);

        $preview = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/discount-preview", [
                'plan_id' => $plan->id,
                'discount_code' => strtolower($code->code),
            ])
            ->assertOk()
            ->json('data');

        $order = $this->checkout($user, $org, $plan, $code->code)['plan_order'];

        $this->assertSame($this->money($order['amount']), $this->money($preview['amount']));
        $this->assertSame($this->money($order['discount_amount']), $this->money($preview['discount_amount']));
        $this->assertSame(400000.0, $this->money($preview['price']));

        // And a refusal looks the same at both doors.
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/discount-preview", [
                'plan_id' => $plan->id,
                'discount_code' => 'NOPE',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.discount_code.0', 'Kode diskon tidak ditemukan atau sudah tidak berlaku.');

        // A code of nothing but spaces is a refusal, not "no code given" — the
        // latter has no row to read a discount off.
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/discount-preview", [
                'plan_id' => $plan->id,
                'discount_code' => '   ',
            ])
            ->assertStatus(422);
    }

    /**
     * The documents name the discount, and the plan line above it reads the
     * catalogue price so the subtraction has something to subtract from.
     *
     * Compared against an undiscounted order rendered from the same template:
     * asserting the discounted one prints a number proves nothing about whether
     * the row is conditional at all.
     */
    public function test_the_invoice_names_the_discount_and_still_totals_correctly(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(price: 400000);
        $code = $this->code(['discount_value' => 25, 'once_per_organization' => false]);

        $discounted = EventPlanOrder::find($this->checkout($user, $this->orgFor($user), $plan, $code->code)['plan_order']['id']);
        $plain = EventPlanOrder::find($this->checkout($user, $this->orgFor($user), $plan)['plan_order']['id']);

        $render = function (EventPlanOrder $order): string {
            $html = view('pdf.invoice', [
                'order' => $order->load('plan', 'organization'),
                'issuer' => config('billing'),
                'dueAt' => Carbon::parse($order->created_at)->addDays(7),
                'bank' => null,
                'money' => fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'),
                'date' => fn ($d) => $d ? Carbon::parse($d)->format('d F Y') : '—',
            ])->render();

            return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        };

        $withCode = $render($discounted);
        $without = $render($plain);

        $this->assertStringContainsString("Diskon {$code->code}", $withCode);
        $this->assertStringContainsString('−Rp 100.000', $withCode);
        // The plan line shows the list price on both documents, so the discount
        // is a subtraction the reader can follow.
        $this->assertStringContainsString('Rp 400.000', $withCode);
        $this->assertStringContainsString('Rp 400.000', $without);
        // And the row is genuinely conditional.
        $this->assertStringNotContainsString('Diskon', $without);

        // Totals: gross, not the plan price.
        $this->assertStringContainsString('Rp '.number_format($discounted->gross_amount, 0, ',', '.'), $withCode);
    }

    /**
     * A fully discounted order settles with `payment_type: 'discount'`, which is
     * not a Midtrans type — the receipt needs its own label or it prints the raw
     * column.
     */
    public function test_the_receipt_labels_a_fully_discounted_payment(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(price: 400000);
        $order = EventPlanOrder::find($this->checkout($user, $this->orgFor($user), $plan, $this->code([
            'discount_type' => 'fixed',
            'discount_value' => 400000,
        ])->code)['plan_order']['id']);

        $html = view('pdf.receipt', [
            'order' => $order->load('plan', 'organization'),
            'issuer' => config('billing'),
            'dueAt' => Carbon::parse($order->created_at)->addDays(7),
            'bank' => null,
            'money' => fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'),
            'date' => fn ($d) => $d ? Carbon::parse($d)->format('d F Y') : '—',
        ])->render();

        $text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $this->assertStringContainsString('Kode diskon (tanpa pembayaran)', $text);
        // Never the raw column value.
        $this->assertStringNotContainsString('Metode bayar discount', $text);
    }

    /**
     * Retrying an unpaid bill re-derives the rail from `amount`, so the discount
     * has to live on the row rather than in a local at checkout — otherwise the
     * second attempt quietly bills full price.
     *
     * Compared against the first attempt's own numbers: asserting the retry
     * "worked" would pass on a build that re-priced it.
     */
    public function test_retrying_an_unpaid_discounted_bill_keeps_the_discount(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);
        $plan = $this->plan(price: 400000);
        $code = $this->code(['discount_value' => 25]);

        $first = $this->checkout($user, $org, $plan, $code->code)['plan_order'];

        // Put it back to outstanding, the state a buyer who closed the Midtrans
        // tab is in.
        EventPlanOrder::whereKey($first['id'])->update([
            'status' => 'past_due',
            'paid_at' => null,
            'receipt_number' => null,
        ]);

        $retried = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/{$first['id']}/pay", [
                'payment_channel' => 'va',
            ])
            ->assertOk()
            ->json('data.plan_order');

        $this->assertSame($this->money($first['amount']), $this->money($retried['amount']));
        $this->assertSame(300000.0, $this->money($retried['amount']));
        $this->assertSame($code->code, $retried['discount_code']);
        $this->assertSame(100000.0, $this->money($retried['discount_amount']));
    }

    /** Money doors are `org.admin`; an operator scans tickets. */
    public function test_an_operator_cannot_checkout_or_preview(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $plan = $this->plan();

        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $this->actingAs($operator, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/plan-orders/discount-preview", [
                'plan_id' => $plan->id,
                'discount_code' => 'ANY',
            ])
            ->assertStatus(403);

        $this->checkout($operator, $org, $plan, null, 403);
    }

    /** The catalogue itself is super-admin-only. */
    public function test_only_a_super_admin_manages_codes(): void
    {
        $plain = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $plan = $this->plan();

        $payload = [
            'code' => 'launch50',
            'discount_type' => 'percent',
            'discount_value' => 50,
            'plan_ids' => [$plan->id],
        ];

        $this->actingAs($plain, 'api')->postJson('/api/v1/admin/discount-codes', $payload)->assertStatus(403);

        $created = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/discount-codes', $payload)
            ->assertCreated()
            ->json('data');

        // Normalised on the way in, so the buyer's spelling cannot miss it.
        $this->assertSame('LAUNCH50', $created['code']);
        $this->assertSame([$plan->id], $created['plan_ids']);
        $this->assertSame(0, $created['redeemed_count']);

        // A percent above 100 is refused rather than clamped on screen.
        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/discount-codes', [...$payload, 'code' => 'TOOBIG', 'discount_value' => 150])
            ->assertStatus(422);

        // Usage is derived: it moves because an order exists, not because
        // anything wrote a counter.
        $user = User::factory()->create();
        $this->checkout($user, $this->orgFor($user), $plan, 'LAUNCH50');

        $listed = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/discount-codes')
            ->assertOk()
            ->json('data');

        $row = collect($listed)->firstWhere('code', 'LAUNCH50');
        $this->assertSame(1, $row['redeemed_count']);
        $this->assertSame(0, $row['reserved_count']);
    }
}
