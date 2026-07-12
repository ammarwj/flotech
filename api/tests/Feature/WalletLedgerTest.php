<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\RefundService;
use App\Services\WalletService;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Balances are denormalized so a payout can lock and check them in one row.
 * That only holds up if they never drift from the ledger — which wallet:audit
 * checks in production, and this asserts here.
 */
class WalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_balances_match_the_ledger_after_a_mixed_sequence(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);

        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);
        $plan->features()->create(['feature_key' => 'qr_tickets', 'value' => 'true']);
        $plan->features()->create(['feature_key' => 'ticket_fee_percent', 'value' => '5']);

        $org = Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
        $event = $org->events()->create([
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'tournament_format' => 'league', 'status' => 'open',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-02',
        ]);
        $category = $event->ticketCategories()->create([
            'name' => 'Reguler', 'price' => 50000, 'quota' => 100, 'is_active' => true,
        ]);
        $org->bankAccounts()->create([
            'bank_name' => 'BCA', 'account_number' => '1234567890',
            'account_holder' => 'Budi', 'is_primary' => true,
        ]);

        $buy = fn (int $qty) => TicketOrder::findOrFail(
            $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
                'ticket_category_id' => $category->id,
                'quantity' => $qty,
                'buyer_name' => 'Budi',
                'buyer_email' => 'budi@test.com',
            ])->assertCreated()->json('data.order.id')
        );

        $wallets = app(WalletService::class);
        $withdrawals = app(WithdrawalService::class);
        $refunds = app(RefundService::class);

        // Two sales, then the event ends and the money is released.
        $orderA = $buy(4);   // 200.000 gross → 190.000 net
        $orderB = $buy(2);   // 100.000 gross →  95.000 net

        Carbon::setTestNow('2026-08-03 12:00:00');
        $wallets->releaseDue();

        // A late sale, credited after the sweep — so it stays held.
        $orderC = $buy(1);   //  50.000 gross →  47.500 net

        // A payout that gets rejected, then one that goes through.
        $rejected = $withdrawals->request($org, $owner, 100000);
        $withdrawals->reject($rejected, $admin, 'Rekening salah');

        $completed = $withdrawals->request($org, $owner, 150000);
        $withdrawals->complete($completed, $admin, 'https://cdn.example.com/bukti.jpg');

        // One refund of released money (a debit), one of money still held (a
        // cancellation).
        $refunds->refundTicketOrder($orderA->fresh(), $admin, 'Komplain');

        $orderD = $buy(3);   // 150.000 gross → 142.500 net, still held
        $refunds->refundTicketOrder($orderD->fresh(), $admin, 'Salah beli');

        $wallets->adjust($org->wallet, -1000, 'Koreksi manual');

        // The invariant: stored balances == the sum of the ledger.
        $this->artisan('wallet:audit')->assertSuccessful();

        $wallet = $org->wallet->fresh();
        $ledger = $wallet->transactions()->get();

        $expectedAvailable = round(
            $ledger->where('status', 'available')->sum(fn ($tx) => $tx->signedAmount()), 2
        );
        $expectedPending = round(
            $ledger->where('status', 'pending')->sum(fn ($tx) => $tx->signedAmount()), 2
        );

        $this->assertSame($expectedAvailable, round((float) $wallet->balance_available, 2));
        $this->assertSame($expectedPending, round((float) $wallet->balance_pending, 2));

        // Order C never left the hold; order D's credit was cancelled outright.
        $this->assertSame(47500.0, round((float) $wallet->balance_pending, 2));
        $this->assertSame(1, $ledger->where('source_id', $orderC->id)->where('status', 'pending')->count());
        $this->assertSame(1, $ledger->where('source_id', $orderD->id)->where('status', 'cancelled')->count());

        // available = 190.000 + 95.000 (released) − 155.000 (completed payout)
        //             − 190.000 (refund of A) − 1.000 (adjustment) = −61.000
        $this->assertSame(-61000.0, round((float) $wallet->balance_available, 2));
        $this->assertSame(150000.0, round((float) $wallet->total_withdrawn, 2));
        $this->assertSame('paid', $orderB->fresh()->status);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
