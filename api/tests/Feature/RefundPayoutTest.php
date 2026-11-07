<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceCategory;
use App\Models\RaceEntry;
use App\Models\RefundPayout;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PlatformSettings;
use App\Services\RaceEntryService;
use App\Services\RefundService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The transfer a refund leaves behind, and its two hops.
 *
 * Every test here **compares** two things that must come out different, because
 * almost nothing in this flow can be proven with one assert. "A payout row
 * exists" stays green when every refund writes one including the free and
 * manual ones, when the amount is rebuilt from the category price instead of
 * read from the ledger, and when completing the transfer credits the wallet
 * straight back and cancels the refund in effect.
 */
class RefundPayoutTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSettings::flush();
        Mail::fake();
        Notification::fake();
        Carbon::setTestNow('2026-01-05 03:00:00'); // 10:00 WIB, inside Early Bird
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- fixtures ----

    private function raceEvent(Organization $org, string $paymentMethod = 'gateway'): Event
    {
        $event = $this->eventOn($org, null, [
            'sport_type' => 'fun_run',
            'status' => 'open',
            'payment_method' => $paymentMethod,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-01',
            'registration_open' => '2026-01-01',
            'registration_close' => '2026-02-28',
        ]);

        $event->racePeriods()->create([
            'name' => 'Early Bird',
            'starts_at' => '2026-01-01T00:00:00+07:00',
            'ends_at' => '2026-01-10T23:59:59+07:00',
            'sort_order' => 0,
        ]);

        return $event->fresh();
    }

    private function category(Event $event, string $name = 'Umum', float $price = 99_000): RaceCategory
    {
        $category = $event->raceCategories()->create([
            'name' => $name,
            'slug' => Str::slug($name),
        ]);

        $category->prices()->create([
            'race_period_id' => $event->racePeriods()->firstOrFail()->id,
            'price' => $price,
        ]);

        return $category;
    }

    private function primaryAccount(Organization $org): BankAccount
    {
        return $org->bankAccounts()->create([
            'account_type' => 'bank',
            'bank_name' => 'BCA',
            'bank_code' => '014',
            'account_number' => '1234567890',
            'account_holder' => 'Ammar WJ',
            'is_primary' => true,
        ]);
    }

    private function paidEntry(Organization $org, Event $event, RaceCategory $category, ?string $code = null): RaceEntry
    {
        $payload = [
            'race_category_id' => $category->id,
            'full_name' => 'Ammar',
            'email' => 'ammar@test.com',
            'phone' => '08123456789',
            'payment_channel' => 'va',
        ];

        if ($code) {
            $payload['discount_code'] = $code;
        }

        $id = $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", $payload)
            ->json('data.entry.id');

        $entry = RaceEntry::findOrFail($id);
        app(RaceEntryService::class)->markPaid($entry);

        return $entry->fresh();
    }

    /** Push every pending credit to `available`, so refunds write real debits. */
    private function releaseCredits(): void
    {
        Carbon::setTestNow('2026-01-06 18:30:00'); // past 01:00 WIB the next day
        app(WalletService::class)->releaseDue();
    }

    private function refund(RaceEntry $entry, ?User $actor = null): void
    {
        app(RefundService::class)->refundRaceEntry(
            $entry->fresh(),
            $actor,
            'Bayar dua kali',
        );
    }

    /**
     * Refund through the organizer's own door, verdict stamp included.
     *
     * Needed wherever a test reads a list that keys off `refund_decided_at`:
     * `RefundService` moves the money but never stamps the verdict — that half
     * belongs to the controller, and the two are paired inside one transaction
     * precisely so neither can run alone in production.
     */
    private function issueRefund(Organization $org, Event $event, RaceEntry $entry): void
    {
        $owner = $org->owner ?? User::findOrFail($org->owner_user_id);

        $this->actingAs($owner, 'api')
            ->postJson(
                "/api/v1/organizations/{$org->id}/events/{$event->id}/race-refunds/{$entry->id}/issue",
                ['reason' => 'Bayar dua kali'],
            )
            ->assertOk();
    }

    // ---- what gets recorded, and what does not ----

    /**
     * > **Invariant: a null reversal writes NOTHING.**
     *
     * Compared a **gateway refund with a manual one** on two events of the same
     * org. Asserting "a row exists for the gateway refund" alone passes even
     * when every refund writes one — and a row for a manual transfer puts a
     * transfer debt in front of a super admin for money the platform never
     * held and the organizer already has in their own account.
     */
    public function test_a_manual_rail_refund_records_no_transfer_debt(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->primaryAccount($org);

        $gatewayEvent = $this->raceEvent($org, 'gateway');
        $manualEvent = $this->raceEvent($org, 'manual');

        $gatewayEntry = $this->paidEntry($org, $gatewayEvent, $this->category($gatewayEvent));
        $manualEntry = $this->paidEntry($org, $manualEvent, $this->category($manualEvent));

        $this->releaseCredits();

        $this->refund($gatewayEntry);
        $this->refund($manualEntry);

        // The pair: one rail owes a transfer, the other cannot.
        $this->assertDatabaseHas('refund_payouts', [
            'source_type' => 'race_entry',
            'source_id' => $gatewayEntry->id,
        ]);
        $this->assertDatabaseMissing('refund_payouts', [
            'source_type' => 'race_entry',
            'source_id' => $manualEntry->id,
        ]);
        $this->assertSame(1, RefundPayout::count());
    }

    /**
     * A credit still `pending` is cancelled in place with no debit, so the
     * organizer was never able to take that money and is owed nothing.
     *
     * Compared against the **same refund after release**: asserting "no row for
     * a pending refund" alone passes even when no refund ever writes one.
     */
    public function test_only_a_released_credit_owes_a_transfer(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->primaryAccount($org);
        $event = $this->raceEvent($org);
        $category = $this->category($event);

        $stillPending = $this->paidEntry($org, $event, $category);
        $released = $this->paidEntry($org, $event, $category);

        // Refund one while its credit is still pending.
        $this->refund($stillPending);

        // Then release and refund the other.
        $this->releaseCredits();
        $this->refund($released);

        // The pair: same rail, same price, different credit state.
        $this->assertDatabaseMissing('refund_payouts', ['source_id' => $stillPending->id]);
        $this->assertDatabaseHas('refund_payouts', ['source_id' => $released->id]);
    }

    /**
     * > **Invariant: the amount is READ from the ledger row, never recomputed.**
     *
     * Compared a **discounted entry with a full-price one** in the same
     * category. Rebuilding the figure from the category price would make the
     * discounted row claim the full 99.000 — the platform would transfer more
     * than it holds, and more than left the organizer's balance. Asserting
     * "amount is 99.000" on the full-price row alone passes either way.
     */
    public function test_the_amount_is_the_ledger_debit_not_the_category_price(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->primaryAccount($org);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', 99_000);

        $event->discountCodes()->create([
            'code' => 'HEMAT',
            'discount_type' => 'fixed',
            'discount_value' => 20_000,
            'is_active' => true,
        ]);

        $full = $this->paidEntry($org, $event, $category);
        $discounted = $this->paidEntry($org, $event, $category, 'HEMAT');

        $this->releaseCredits();

        $this->refund($full);
        $this->refund($discounted);

        $fullPayout = RefundPayout::where('source_id', $full->id)->firstOrFail();
        $discountedPayout = RefundPayout::where('source_id', $discounted->id)->firstOrFail();

        // The pair: the discounted row must NOT carry the category price.
        $this->assertSame(99_000.0, (float) $fullPayout->amount);
        $this->assertSame(79_000.0, (float) $discountedPayout->amount);

        // And each equals what actually left the wallet.
        $this->assertSame(
            (float) $full->fresh()->price,
            (float) $fullPayout->amount,
        );
        $this->assertSame(
            (float) $discounted->fresh()->price,
            (float) $discountedPayout->amount,
        );
    }

    /** One refund, one debt — a replayed refund must not queue a second transfer. */
    public function test_recording_a_payout_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->primaryAccount($org);
        $event = $this->raceEvent($org);
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $this->releaseCredits();
        $this->refund($entry);

        // Re-running record() directly, the way a retried webhook would.
        app(\App\Services\RefundPayoutService::class)->record(
            'race_entry',
            $entry->id,
            $org->fresh(),
            $event->id,
            \App\Models\WalletTransaction::where('source_id', $entry->id)
                ->where('type', 'debit')
                ->firstOrFail(),
            'Ammar',
            'Diulang',
        );

        $this->assertSame(1, RefundPayout::where('source_id', $entry->id)->count());
    }

    /** The organizer's own destination is snapshotted, not joined. */
    public function test_the_destination_is_snapshotted_at_refund_time(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $account = $this->primaryAccount($org);
        $event = $this->raceEvent($org);
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $this->releaseCredits();
        $this->refund($entry);

        $payout = RefundPayout::where('source_id', $entry->id)->firstOrFail();

        // Editing the account afterwards must not rewrite where money was sent.
        $account->update(['account_number' => '9999999999', 'bank_name' => 'Mandiri']);

        $payout->refresh();

        $this->assertSame('1234567890', $payout->account_number);
        $this->assertSame('BCA', $payout->bank_name);
        $this->assertSame('Ammar WJ', $payout->account_holder);
    }

    // ---- hop one: the platform pays the organizer ----

    /**
     * > **Invariant: completing a payout must not touch the wallet.**
     *
     * Compared the balance at **three** points: before the refund, after it,
     * and after `complete()`. The last two must be EQUAL. Asserting "the
     * balance dropped on refund" alone passes even when completing credits it
     * straight back — which would raise the organizer's balance again and
     * cancel the refund in effect, crediting them for the very money being
     * returned.
     */
    public function test_completing_a_transfer_leaves_the_wallet_untouched(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->primaryAccount($org);
        $event = $this->raceEvent($org);
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $this->releaseCredits();

        $wallet = Wallet::where('organization_id', $org->id)->firstOrFail();
        $before = (float) $wallet->fresh()->balance_available;

        $this->refund($entry);
        $afterRefund = (float) $wallet->fresh()->balance_available;

        $payout = RefundPayout::where('source_id', $entry->id)->firstOrFail();

        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/refund-payouts/{$payout->id}/complete", [
                'proof_url' => 'https://cdn.test/bukti.jpg',
                'transfer_reference' => 'TRX-991',
            ])
            ->assertOk();

        $afterComplete = (float) $wallet->fresh()->balance_available;

        // The refund took money out...
        $this->assertSame($before - 99_000.0, $afterRefund);
        // ...and paying it out did NOT put it back.
        $this->assertSame($afterRefund, $afterComplete);
    }

    /** A second completion must not overwrite the first transfer's proof. */
    public function test_completing_twice_is_refused(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->primaryAccount($org);
        $event = $this->raceEvent($org);
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $this->releaseCredits();
        $this->refund($entry);

        $payout = RefundPayout::where('source_id', $entry->id)->firstOrFail();
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/refund-payouts/{$payout->id}/complete", [
                'proof_url' => 'https://cdn.test/pertama.jpg',
            ])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/refund-payouts/{$payout->id}/complete", [
                'proof_url' => 'https://cdn.test/kedua.jpg',
            ])
            ->assertStatus(422);

        $this->assertSame('https://cdn.test/pertama.jpg', $payout->fresh()->proof_url);
    }

    // ---- hop two: the organizer pays the participant ----

    /**
     * Forwarding before the platform has paid is refused.
     *
     * Compared **before and after** `complete()` on the same row: asserting the
     * 422 alone passes even when forwarding is refused forever, which would
     * leave the chain permanently stuck one hop short.
     */
    public function test_forwarding_is_refused_until_the_platform_has_paid(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->primaryAccount($org);
        $event = $this->raceEvent($org);
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $this->releaseCredits();
        $this->refund($entry);

        $payout = RefundPayout::where('source_id', $entry->id)->firstOrFail();
        $url = "/api/v1/organizations/{$org->id}/refund-payouts/{$payout->id}/forward";

        // Pending at the platform: nothing to forward yet.
        $this->actingAs($owner, 'api')
            ->patchJson($url, ['forwarded_proof_url' => 'https://cdn.test/terus.jpg'])
            ->assertStatus(422);

        $this->assertNull($payout->fresh()->forwarded_at);

        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/refund-payouts/{$payout->id}/complete", [
                'proof_url' => 'https://cdn.test/bukti.jpg',
            ])
            ->assertOk();

        // Paid now: the same request goes through.
        $this->actingAs($owner, 'api')
            ->patchJson($url, ['forwarded_proof_url' => 'https://cdn.test/terus.jpg'])
            ->assertOk();

        $payout->refresh();
        $this->assertNotNull($payout->forwarded_at);
        $this->assertSame('https://cdn.test/terus.jpg', $payout->forwarded_proof_url);
        $this->assertFalse($payout->isAwaitingForwarding());
    }

    /**
     * > **Behind `org.admin`, not plain `tenant`.**
     *
     * THREE actors on one payload: asserting the owner succeeds passes
     * identically under plain `tenant`, which admits the gate operators.
     */
    public function test_forwarding_requires_org_admin(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->primaryAccount($org);
        $event = $this->raceEvent($org);
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $this->releaseCredits();
        $this->refund($entry);

        $payout = RefundPayout::where('source_id', $entry->id)->firstOrFail();

        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/refund-payouts/{$payout->id}/complete", [
                'proof_url' => 'https://cdn.test/bukti.jpg',
            ])
            ->assertOk();

        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $outsider = User::factory()->create();

        $url = "/api/v1/organizations/{$org->id}/refund-payouts/{$payout->id}/forward";
        $payload = ['forwarded_proof_url' => 'https://cdn.test/terus.jpg'];

        $this->actingAs($operator, 'api')->patchJson($url, $payload)->assertStatus(403);
        $this->actingAs($outsider, 'api')->patchJson($url, $payload)->assertStatus(403);
        $this->actingAs($owner, 'api')->patchJson($url, $payload)->assertOk();
    }

    /** The organizer cannot reach the platform's own transfer columns. */
    public function test_the_organizer_cannot_write_the_platform_half(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->primaryAccount($org);
        $event = $this->raceEvent($org);
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $this->releaseCredits();
        $this->refund($entry);

        $payout = RefundPayout::where('source_id', $entry->id)->firstOrFail();

        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/refund-payouts/{$payout->id}/complete", [
                'proof_url' => 'https://cdn.test/asli.jpg',
                'transfer_reference' => 'TRX-ASLI',
            ])
            ->assertOk();

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/refund-payouts/{$payout->id}/forward", [
                'forwarded_proof_url' => 'https://cdn.test/terus.jpg',
                // Smuggled in: each of these has exactly one writer elsewhere.
                'status' => 'pending',
                'amount' => 1,
                'proof_url' => 'https://cdn.test/palsu.jpg',
                'transfer_reference' => 'TRX-PALSU',
            ])
            ->assertOk();

        $payout->refresh();

        $this->assertSame('completed', $payout->status);
        $this->assertSame(99_000.0, (float) $payout->amount);
        $this->assertSame('https://cdn.test/asli.jpg', $payout->proof_url);
        $this->assertSame('TRX-ASLI', $payout->transfer_reference);
    }

    // ---- notifications ----

    /**
     * The gap this feature exists to close: somebody has to be told.
     *
     * Compared the **two hops**: a refund notifies the platform, completing it
     * notifies the organizer. Asserting one alone passes while the other side
     * is silent, which is the original bug in half.
     */
    public function test_each_hop_notifies_the_side_that_now_owes_the_money(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->primaryAccount($org);
        $event = $this->raceEvent($org);
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->releaseCredits();
        $this->refund($entry);

        Notification::assertSentTo($admin, \App\Notifications\RefundAwaitingTransfer::class);
        Notification::assertNotSentTo($owner, \App\Notifications\RefundTransferCompleted::class);

        $payout = RefundPayout::where('source_id', $entry->id)->firstOrFail();

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/refund-payouts/{$payout->id}/complete", [
                'proof_url' => 'https://cdn.test/bukti.jpg',
            ])
            ->assertOk();

        Notification::assertSentTo($owner, \App\Notifications\RefundTransferCompleted::class);
    }

    // ---- the queues ----

    /** The platform's queue is oldest-first, and keeps its history. */
    public function test_the_admin_queue_splits_pending_from_completed(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->primaryAccount($org);
        $event = $this->raceEvent($org);
        $category = $this->category($event);

        $first = $this->paidEntry($org, $event, $category);
        $second = $this->paidEntry($org, $event, $category);

        $this->releaseCredits();

        $this->refund($first);
        Carbon::setTestNow('2026-01-06 19:30:00');
        $this->refund($second);

        $admin = User::factory()->create(['role' => 'super_admin']);

        $pending = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/refund-payouts')
            ->assertOk()
            ->json('data.pending');

        // Oldest first: whoever waited longest is at the top.
        $this->assertCount(2, $pending);
        $this->assertSame($first->id, $pending[0]['source_id']);

        $payoutId = $pending[0]['id'];
        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/refund-payouts/{$payoutId}/complete", [
                'proof_url' => 'https://cdn.test/bukti.jpg',
            ])
            ->assertOk();

        $body = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/refund-payouts')
            ->assertOk()
            ->json('data');

        // The pair: one moved across, the history kept it.
        $this->assertCount(1, $body['pending']);
        $this->assertCount(1, $body['completed']);
    }

    /** The organizer sees rows still pending at the platform too. */
    public function test_the_organizer_queue_includes_rows_the_platform_has_not_paid(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->primaryAccount($org);
        $event = $this->raceEvent($org);
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $this->releaseCredits();
        $this->refund($entry);

        $rows = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/refund-payouts")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['awaiting_transfer']);
        // Nothing to forward yet — the server says so rather than letting the
        // client assemble it from status + forwarded_at.
        $this->assertFalse($rows[0]['awaiting_forwarding']);
    }

    /**
     * The organizer's per-event refund queue must say where the money got to.
     *
     * Compared the **decided row before and after** the platform's transfer: the
     * payload has to move from `queued` to `with_organizer`. Asserting the row
     * is present alone passes even with `refund_transfer` absent entirely —
     * which is what happened, because `refund_transfer` is `whenLoaded` and the
     * queue was loading only `category`. The screen then said "Disetujui"
     * forever over money already transferred, which is the silence this whole
     * feature exists to end.
     */
    public function test_the_event_refund_queue_reports_the_transfer_stage(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->primaryAccount($org);
        $event = $this->raceEvent($org);
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $this->releaseCredits();

        // Through the endpoint, not RefundService directly: the verdict stamp
        // is the controller's half, and `decided` keys off
        // `refund_decided_at`. Calling the service alone leaves the row out of
        // this list entirely, so the test would be asserting about a payload
        // the page never receives.
        $this->issueRefund($org, $event, $entry);

        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/race-refunds";

        $before = $this->actingAs($owner, 'api')->getJson($url)->assertOk()->json('data.decided');

        $this->assertCount(1, $before);
        $this->assertSame('queued', $before[0]['refund_transfer']['stage']);

        $payout = RefundPayout::where('source_id', $entry->id)->firstOrFail();
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/refund-payouts/{$payout->id}/complete", [
                'proof_url' => 'https://cdn.test/bukti.jpg',
            ])
            ->assertOk();

        $after = $this->actingAs($owner, 'api')->getJson($url)->assertOk()->json('data.decided');

        // The pair: the same row, a stage further along.
        $this->assertSame('with_organizer', $after[0]['refund_transfer']['stage']);

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/refund-payouts/{$payout->id}/forward", [
                'forwarded_proof_url' => 'https://cdn.test/terus.jpg',
            ])
            ->assertOk();

        $final = $this->actingAs($owner, 'api')->getJson($url)->assertOk()->json('data.decided');

        $this->assertSame('forwarded', $final[0]['refund_transfer']['stage']);
    }

    /**
     * A refund that owes no transfer publishes NULL, not a stage.
     *
     * Compared against the row above: without this the page would print
     * "menunggu ditransfer flo-event" over a manual-rail refund whose money
     * never passed through the platform and never will.
     */
    public function test_a_refund_with_no_transfer_debt_reports_no_stage(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->primaryAccount($org);
        $event = $this->raceEvent($org, 'manual');
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $this->releaseCredits();
        $this->issueRefund($org, $event, $entry);

        $decided = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-refunds")
            ->assertOk()
            ->json('data.decided');

        $this->assertCount(1, $decided);
        $this->assertNull($decided[0]['refund_transfer']);
    }

    /** Another org's refund debts are not readable. */
    public function test_the_organizer_queue_is_scoped_to_its_own_org(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->primaryAccount($org);
        $event = $this->raceEvent($org);
        $entry = $this->paidEntry($org, $event, $this->category($event));

        $this->releaseCredits();
        $this->refund($entry);

        $outsiderOwner = User::factory()->create();
        $otherOrg = $this->orgFor($outsiderOwner);

        $rows = $this->actingAs($outsiderOwner, 'api')
            ->getJson("/api/v1/organizations/{$otherOrg->id}/refund-payouts")
            ->assertOk()
            ->json('data');

        $this->assertCount(0, $rows);
    }
}
