<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

class WithdrawalTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    /** Put money straight in the available balance; income is covered elsewhere. */
    private function fund(Organization $org, float $available): Wallet
    {
        return Wallet::create([
            'organization_id' => $org->id,
            'balance_available' => $available,
            'total_earned' => $available,
        ]);
    }

    /**
     * An available credit traceable to one event, written as a real ledger row.
     *
     * `fund()` above deliberately writes none, which is why the minimum tests
     * built on it still see a waiver of zero — the exemption is derived from the
     * ledger, so proving anything about it needs rows that actually exist.
     */
    private function fundFromEvent(Organization $org, Event $event, float $amount): Wallet
    {
        // firstOrCreate, not `$org->wallet ?? create`: the relation is cached on
        // the model, so a second call in one test would try to insert twice.
        $wallet = Wallet::firstOrCreate(['organization_id' => $org->id]);

        $wallet->transactions()->create([
            'organization_id' => $org->id,
            'event_id' => $event->id,
            'type' => 'credit',
            'category' => 'ticket_sale',
            'status' => 'available',
            'amount' => $amount,
            'gross_amount' => $amount,
            'description' => 'Penjualan tiket',
        ]);

        $wallet->increment('balance_available', $amount);
        $wallet->increment('total_earned', $amount);

        return $wallet->fresh();
    }

    /** The wallet's exempt figure, read fresh — never off a cached relation. */
    private function waived(Organization $org): float
    {
        return Wallet::where('organization_id', $org->id)->firstOrFail()->minimumWaivedBalance();
    }

    /** An event on this test's plan, in the status given. */
    private function eventWithStatus(Organization $org, string $status): Event
    {
        return $this->eventOn($org, null, ['status' => $status]);
    }

    private function bank(Organization $org): void
    {
        $org->bankAccounts()->create([
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder' => 'Budi Santoso',
            'is_primary' => true,
        ]);
    }

    public function test_withdrawal_requires_a_bank_account(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->fund($org, 500000);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 200000])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tambahkan rekening bank atau e-wallet terlebih dahulu.');
    }

    public function test_withdrawal_below_the_minimum_is_rejected(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->fund($org, 500000);
        $this->bank($org);

        // config default minimum is 100.000
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 50000])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount', 'Jumlah di bawah minimal penarikan.');

        $this->assertDatabaseCount('withdrawals', 0);
    }

    /**
     * The waiver, stated as the comparison the rule is actually about: same
     * org, same amount, same 60.000 of available money — only the event's
     * status differs. Asserting the accepted half alone would pass even if the
     * minimum had simply stopped being enforced.
     */
    public function test_the_minimum_is_waived_only_for_money_from_an_ended_event(): void
    {
        $user = User::factory()->create();
        $live = $this->org($user);
        $this->bank($live);
        $this->fundFromEvent($live, $this->eventWithStatus($live, 'open'), 60000);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$live->id}/withdrawals", ['amount' => 50000])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount', 'Jumlah di bawah minimal penarikan.');

        $ended = $this->org($user);
        $this->bank($ended);
        $this->fundFromEvent($ended, $this->eventWithStatus($ended, 'finished'), 60000);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$ended->id}/withdrawals", ['amount' => 50000])
            ->assertCreated();

        $row = Withdrawal::where('organization_id', $ended->id)->firstOrFail();

        // Not 0: the minimum is a legal setting at 0, so the snapshot has to
        // keep saying what it was. `exempt_consumed` is what marks a waiver.
        $this->assertSame('100000.00', (string) $row->minimum_at_request);
        $this->assertSame(
            '55000.00',
            (string) $row->exempt_consumed,
            'The whole debit (50.000 + 5.000 fee) came out of the exempt stock.',
        );
    }

    /**
     * `cancelled` is exempt for the same reason `finished` is — it can never
     * earn again. Compared against `open` because "a cancelled event is
     * withdrawable" is only meaningful next to a status that is not.
     */
    public function test_a_cancelled_event_waives_the_minimum_like_a_finished_one(): void
    {
        $user = User::factory()->create();

        $open = $this->org($user);
        $this->bank($open);
        $this->fundFromEvent($open, $this->eventWithStatus($open, 'open'), 60000);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$open->id}/withdrawals", ['amount' => 40000])
            ->assertStatus(422);

        $cancelled = $this->org($user);
        $this->bank($cancelled);
        $this->fundFromEvent($cancelled, $this->eventWithStatus($cancelled, 'cancelled'), 60000);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$cancelled->id}/withdrawals", ['amount' => 40000])
            ->assertCreated();
    }

    /**
     * The waiver is per event, not per wallet: a below-minimum payout may reach
     * the ended event's share and no further. Two requests on one wallet, and
     * the pair is the proof — the accepted one alone would also pass a rule
     * that waived the whole balance.
     */
    public function test_the_waiver_covers_only_the_ended_events_share(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->bank($org);

        $this->fundFromEvent($org, $this->eventWithStatus($org, 'finished'), 30000);
        $this->fundFromEvent($org, $this->eventWithStatus($org, 'open'), 70000);

        // 40.000 reaches past the 30.000 that is exempt.
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 40000])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount', 'Jumlah di bawah minimal penarikan.');

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 30000])
            ->assertCreated();
    }

    /**
     * An ordinary above-minimum payout from a LIVE event must not eat into the
     * waiver. This is the bug that killed the stateless "stock minus every
     * withdrawal" formula: with the drain unrecorded, one normal payout drove
     * the figure permanently negative and the feature died silently, green in
     * every single-step test. Hence the before/after comparison.
     */
    public function test_an_ordinary_payout_does_not_consume_the_waiver(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->bank($org);

        $this->fundFromEvent($org, $this->eventWithStatus($org, 'finished'), 40000);
        $this->fundFromEvent($org, $this->eventWithStatus($org, 'open'), 300000);

        $this->assertSame(40000.0, $this->waived($org));

        $id = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 200000])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            0.0,
            (float) Withdrawal::findOrFail($id)->exempt_consumed,
            'A payout well above the minimum never needed the waiver, so it must not draw on it.',
        );
        $this->assertSame(40000.0, $this->waived($org));
    }

    /**
     * The stock is finite. Two waived payouts in a row: the second finds it
     * spent. Asserting one alone would pass even if the draw were never
     * recorded at all — which is precisely the failure mode.
     */
    public function test_the_waiver_is_exhausted_by_the_payouts_that_use_it(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->bank($org);

        $this->fundFromEvent($org, $this->eventWithStatus($org, 'finished'), 60000);
        // Ordinary money, so the balance itself never runs out and cannot be
        // what explains the refusal below.
        $this->fundFromEvent($org, $this->eventWithStatus($org, 'open'), 400000);

        $first = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 50000])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame('55000.00', (string) Withdrawal::findOrFail($first)->exempt_consumed);

        // Settled, not cancelled: a cancellation would hand the waiver back
        // (its own test below), and only one payout may be open at a time.
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/withdrawals/{$first}/complete", [
                'proof_url' => 'https://cdn.example.test/bukti.jpg',
            ])
            ->assertOk();

        // 60.000 of stock minus a 55.000 draw leaves 5.000, so a second small
        // payout is refused — by the waiver, not the balance: 400.000 of
        // ordinary money is still sitting there.
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 50000])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount', 'Jumlah di bawah minimal penarikan.');

        $this->assertSame(5000.0, $this->waived($org));
    }

    /**
     * A rejected or cancelled payout gives the money back, so it must give the
     * waiver back too — and it does without a second flag, because the
     * consumption tally only counts payouts still holding funds.
     */
    public function test_cancelling_a_waived_payout_restores_the_waiver(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->bank($org);

        $this->fundFromEvent($org, $this->eventWithStatus($org, 'finished'), 60000);

        $before = $this->waived($org);

        $id = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 50000])
            ->assertCreated()
            ->json('data.id');

        $this->assertLessThan($before, $this->waived($org));

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/withdrawals/{$id}")
            ->assertOk();

        $this->assertSame($before, $this->waived($org));
    }

    /**
     * The waiver reads the event's status live, so cancelling and restoring is
     * not a one-way door — the same comparison `WalletReleaseTest` makes about
     * the sweep.
     */
    public function test_the_waiver_follows_the_events_status_both_ways(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->eventWithStatus($org, 'open');
        $this->fundFromEvent($org, $event, 60000);

        $this->assertSame(0.0, $this->waived($org));

        $event->update(['status' => 'finished']);
        $this->assertSame(60000.0, $this->waived($org));

        // Nothing about the ledger was rewritten, so it moves back as well.
        $event->update(['status' => 'open']);
        $this->assertSame(0.0, $this->waived($org));
    }

    /**
     * A super admin's correction carries no `event_id`, so it can never confer
     * exemption. `adjust()` has no source and is not idempotent; a mistyped
     * 60.000 there must not become instantly withdrawable.
     */
    public function test_an_admin_adjustment_never_waives_the_minimum(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->bank($org);

        $wallet = $this->fund($org, 0);
        app(\App\Services\WalletService::class)->adjust($wallet, 60000, 'Koreksi manual');

        $this->assertSame(0.0, $this->waived($org));

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 50000])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount', 'Jumlah di bawah minimal penarikan.');
    }

    /**
     * The admin fee is NOT waived: what can be withdrawn is always the balance
     * minus the fee, even for money from an ended event. A balance under the
     * fee cannot be withdrawn at all, and that lands on the existing
     * "insufficient balance" message rather than a new error path.
     */
    public function test_the_admin_fee_is_not_waived(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->bank($org);

        $this->fundFromEvent($org, $this->eventWithStatus($org, 'finished'), 4000);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 4000])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount', 'Saldo tidak mencukupi.');

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/wallet")
            ->assertOk()
            ->assertJsonPath('data.balance_minimum_waived', 4000)
            // 4.000 − 5.000 floors at 0: the UI must be able to say so instead
            // of offering a waiver the fee makes unusable.
            ->assertJsonPath('data.max_withdrawable', 0);
    }

    /**
     * `wallet_transactions.event_id` is `nullOnDelete`, so a deletable event
     * carrying ledger rows would silently move its money out of the exempt
     * stock. `EventController::destroy()` is what keeps that impossible —
     * anything that has taken money is refused.
     */
    public function test_an_event_with_wallet_rows_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->eventWithStatus($org, 'finished');
        $this->fundFromEvent($org, $event, 60000);

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/events/{$event->id}")
            ->assertStatus(422);

        $this->assertSame(60000.0, $this->waived($org));
    }

    /** The admin fee comes out of the same balance, so it must be covered too. */
    public function test_balance_must_cover_the_amount_plus_the_admin_fee(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->fund($org, 100000); // exactly the amount, but 5.000 short of amount + fee
        $this->bank($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount', 'Saldo tidak mencukupi.');
    }

    public function test_pending_funds_cannot_be_withdrawn(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        Wallet::create(['organization_id' => $org->id, 'balance_pending' => 500000, 'balance_available' => 0]);
        $this->bank($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 200000])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount', 'Saldo tidak mencukupi.');
    }

    public function test_request_holds_the_funds_immediately(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->fund($org, 200000);
        $this->bank($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->assertCreated()
            ->assertJsonPath('data.amount', 100000)
            ->assertJsonPath('data.admin_fee', 5000)
            ->assertJsonPath('data.total_debit', 105000)
            ->assertJsonPath('data.status', 'pending');

        // 200.000 − (100.000 + 5.000) = 95.000 left available.
        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_available' => '95000.00',
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'organization_id' => $org->id,
            'type' => 'debit',
            'category' => 'withdrawal',
            'status' => 'available',
            'amount' => '105000.00',
        ]);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/wallet")
            ->assertOk()
            ->assertJsonPath('data.balance_on_hold', 105000)
            ->assertJsonPath('data.has_active_withdrawal', true);
    }

    public function test_only_one_active_withdrawal_at_a_time(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->fund($org, 500000);
        $this->bank($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->assertCreated();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Masih ada permintaan penarikan yang sedang diproses.');

        $this->assertDatabaseCount('withdrawals', 1);
    }

    public function test_organizer_can_cancel_a_pending_withdrawal_and_get_the_funds_back(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->fund($org, 200000);
        $this->bank($org);

        $id = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 100000])
            ->json('data.id');

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/withdrawals/{$id}")
            ->assertOk();

        $this->assertDatabaseHas('wallets', [
            'organization_id' => $org->id,
            'balance_available' => '200000.00',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'category' => 'withdrawal_reversal',
            'type' => 'credit',
            'amount' => '105000.00',
        ]);
    }

    /**
     * The gate-scanning operator is a full tenant member, so `tenant` alone
     * would let them repoint the payout account.
     */
    public function test_operator_member_cannot_touch_the_wallet(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $org = $this->org($owner);
        $this->fund($org, 500000);
        $this->bank($org);

        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $this->actingAs($operator, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/wallet")
            ->assertStatus(403);

        $this->actingAs($operator, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/bank-accounts", [
                'bank_name' => 'BCA', 'account_number' => '999', 'account_holder' => 'Maling',
            ])
            ->assertStatus(403);

        $this->actingAs($operator, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/withdrawals", ['amount' => 200000])
            ->assertStatus(403);
    }

    public function test_org_admin_member_can_use_the_wallet(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $org = $this->org($owner);
        $this->fund($org, 500000);
        $org->members()->create(['user_id' => $admin->id, 'role' => 'admin']);

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/wallet")
            ->assertOk()
            ->assertJsonPath('data.balance_available', 500000);
    }

    public function test_outsider_cannot_read_another_orgs_wallet(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $org = $this->org($owner);
        $this->fund($org, 500000);

        $this->actingAs($outsider, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/wallet")
            ->assertStatus(403);
    }

    public function test_adding_a_bank_account_demotes_the_previous_primary(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $this->bank($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/bank-accounts", [
                'bank_name' => 'Mandiri', 'account_number' => '9876543210', 'account_holder' => 'Budi Santoso',
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_primary', true)
            // The organizer only sees the tail of their own number.
            ->assertJsonPath('data.account_number', '******3210');

        $this->assertSame(1, $org->bankAccounts()->where('is_primary', true)->count());
    }
}
