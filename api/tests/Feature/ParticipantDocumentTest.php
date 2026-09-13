<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Team;
use App\Models\TicketOrder;
use App\Models\User;
use App\Notifications\RegistrationPaid;
use App\Services\PlatformSettings;
use App\Services\RegistrationService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Invoices and receipts for the two payments a participant makes: buying
 * tickets and paying a registration fee.
 *
 * The issuer on these is the organizer, not the platform — that money is
 * credited to their wallet, so the sale is theirs.
 */
class ParticipantDocumentTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function orgWithPlan(User $owner, array $features = []): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);
        foreach ($features as $key => $value) {
            $plan->features()->create(['feature_key' => $key, 'value' => $value]);
        }

        $this->testPlan = $plan;

        return Organization::create([
            'name' => 'Org Dok',
            'slug' => 'org-'.uniqid(),
            'owner_id' => $owner->id,
            'contact_email' => 'panitia@example.test',
            'contact_phone' => '08123456789',
        ]);
    }

    private function event(Organization $org): Event
    {
        return $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'tournament_format' => 'league', 'status' => 'open',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-02',
        ]);
    }

    /** Buy `quantity` tickets of a category priced at `$price`. */
    private function buy(Organization $org, Event $event, float $price, string $email = 'budi@test.com'): TicketOrder
    {
        $category = $event->ticketCategories()->create([
            'name' => $price > 0 ? 'Reguler' : 'Gratis',
            'price' => $price,
            'is_active' => true,
        ]);

        $payload = [
            'ticket_category_id' => $category->id,
            'quantity' => 2,
            'buyer_name' => 'Budi',
            'buyer_email' => $email,
        ];

        if ($price > 0) {
            $payload['payment_channel'] = 'va';
        }

        $id = $this->postJson(
            "/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase",
            $payload,
        )->assertSuccessful()->json('data.order.id');

        return TicketOrder::findOrFail($id);
    }

    /**
     * A free ticket is settled without money moving, so it must produce no
     * document at all. Compared against a paid order on the same event: assert
     * only that the paid one has numbers and the test still passes while the
     * free one quietly issues a Rp 0 invoice.
     */
    public function test_only_a_paid_order_gets_documents(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true', 'payment_gateway' => 'true']);
        $event = $this->event($org);

        $free = $this->buy($org, $event, 0, 'gratis@test.com');
        $paid = $this->buy($org, $event, 50_000, 'bayar@test.com');

        $this->assertNull($free->invoice_number);
        $this->assertNull($free->fresh()->receipt_number, 'a free order settles instantly — and still gets nothing');

        $this->assertNotNull($paid->invoice_number);

        $this->getJson("/api/v1/ticket-orders/{$free->id}/invoice")->assertStatus(404);
        $this->getJson("/api/v1/ticket-orders/{$free->id}/receipt")->assertStatus(404);

        $this->getJson("/api/v1/ticket-orders/{$paid->id}/invoice")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * The buyer never signs up, so the unguessable order id is the credential.
     * This is the test that fails the moment `auth:api` creeps onto the route.
     */
    public function test_a_guest_buyer_can_download_their_own_documents(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true', 'payment_gateway' => 'true']);
        $event = $this->event($org);

        $order = $this->buy($org, $event, 50_000);
        $this->assertNull($order->buyer_user_id, 'sanity: this purchase was made without logging in');

        $this->getJson("/api/v1/ticket-orders/{$order->id}/invoice")->assertOk();

        app(TicketService::class)->markPaid($order->fresh());

        $receipt = $this->get("/api/v1/ticket-orders/{$order->id}/receipt")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // A real PDF, not an empty body — a filename alone would pass on a
        // broken render.
        $this->assertStringStartsWith('%PDF', $receipt->getContent());
    }

    /**
     * The organizer sells the ticket and is credited for it, so the document is
     * issued in their name — not the platform's. Asserted by comparing against
     * the platform identity that the plan-order documents carry.
     */
    public function test_the_organizer_is_the_issuer_not_the_platform(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true', 'payment_gateway' => 'true']);
        $event = $this->event($org);

        $order = $this->buy($org, $event, 50_000);
        app(TicketService::class)->markPaid($order);

        $html = view('pdf.participant-receipt', $this->viewData($order->fresh()))->render();

        $this->assertStringContainsString($org->name, $html);
        $this->assertStringContainsString('panitia@example.test', $html);
        $this->assertStringNotContainsString((string) config('billing.issuer_address'), $html);
    }

    /**
     * PPN is a line of its own, and it is snapshotted rather than recomputed.
     *
     * The tax was being dropped on the way in — PaymentFeeCalculator returned
     * it, nothing stored it — so the document could only ever print one merged
     * "Biaya pembayaran". Compared against a row with no tax, which must still
     * fall back to the merged line: asserting the PPN row appears would pass
     * while an untaxed order grew a "PPN Rp 0".
     */
    public function test_the_document_shows_ppn_as_its_own_line_when_there_is_tax(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true', 'payment_gateway' => 'true']);
        $event = $this->event($org);

        $taxed = $this->buy($org, $event, 50_000, 'pajak@test.com');
        $this->assertGreaterThan(0, (float) $taxed->gateway_tax, 'the calculator returns it — the row has to keep it');
        $this->assertLessThan(
            (float) $taxed->gateway_fee,
            (float) $taxed->gateway_tax,
            'tax is part of the fee, never an amount on top of it',
        );

        $html = view('pdf.participant-invoice', $this->viewData($taxed->fresh()))->render();
        $this->assertStringContainsString('PPN', $html);
        $this->assertStringContainsString('Biaya payment gateway', $html);

        // A row settled before the column existed reports 0 and keeps the
        // single merged line rather than printing "PPN Rp 0".
        $taxed->update(['gateway_tax' => 0]);
        $untaxed = view('pdf.participant-invoice', $this->viewData($taxed->fresh()))->render();
        $this->assertStringNotContainsString('PPN', $untaxed);
        $this->assertStringContainsString('Biaya pembayaran', $untaxed);
    }

    /**
     * Re-delivered Midtrans webhooks call markPaid() again; a second receipt
     * number for one payment would put two documents into somebody's books.
     */
    public function test_settling_twice_does_not_reissue_the_receipt(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true', 'payment_gateway' => 'true']);
        $event = $this->event($org);

        $order = $this->buy($org, $event, 50_000);
        $tickets = app(TicketService::class);

        $tickets->markPaid($order);
        $first = $order->fresh()->receipt_number;

        $tickets->markPaid($order->fresh());

        $this->assertNotNull($first);
        $this->assertSame($first, $order->fresh()->receipt_number);
    }

    /**
     * Each stream keeps its own monthly sequence. Asserting the run alone would
     * pass even if tickets and plan orders shared one prefix and interleaved.
     */
    public function test_numbers_run_per_month_and_per_stream(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true', 'payment_gateway' => 'true']);
        $event = $this->event($org);

        $first = $this->buy($org, $event, 50_000, 'a@test.com');
        $second = $this->buy($org, $event, 50_000, 'b@test.com');

        $period = now()->format('Y/m');
        $prefix = config('billing.ticket_invoice_prefix');

        $this->assertSame("{$prefix}/{$period}/0001", $first->invoice_number);
        $this->assertSame("{$prefix}/{$period}/0002", $second->invoice_number);
        $this->assertNotSame(config('billing.invoice_prefix'), $prefix);
    }

    /**
     * Registration fees: same documents, different party being billed, and the
     * manager's login is what guards them.
     */
    public function test_registration_documents_belong_to_the_manager_alone(): void
    {
        PlatformSettings::put(['payment_gateway_enabled' => true], null);
        PlatformSettings::flush();

        $manager = User::factory()->create();
        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner, ['online_registration' => 'true', 'payment_gateway' => 'true']);
        $event = $this->event($org);
        $category = $event->categories()->create([
            'name' => 'Putra', 'slug' => 'putra', 'tournament_format' => 'league', 'registration_fee' => 75_000,
        ]);

        $team = Team::create([
            'event_id' => $event->id,
            'category_id' => $category->id,
            'name' => 'Garuda',
            'contact_name' => 'Andi',
            'contact_phone' => '0811',
            'manager_user_id' => $manager->id,
            'status' => 'pending',
            'registered_at' => now(),
        ]);

        // Manual rail: the one case where a bill genuinely outlives its
        // payment. Without a Midtrans key the gateway branch settles instantly
        // (`mock`), so this is the only way to observe an unpaid document.
        PlatformSettings::put(['payment_gateway_enabled' => false], null);
        PlatformSettings::flush();
        $org->bankAccounts()->create([
            'bank_name' => 'BCA', 'account_number' => '123', 'account_holder' => 'Panitia', 'is_primary' => true,
        ]);

        app(RegistrationService::class)->startPayment($team, null);
        $team->refresh();

        $this->assertNotNull($team->invoice_number, 'the bill exists before the money does');
        $this->assertNull($team->receipt_number);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/my-teams/{$team->id}/receipt")
            ->assertStatus(404);

        $this->actingAs($manager, 'api')
            ->get("/api/v1/my-teams/{$team->id}/invoice")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        app(RegistrationService::class)->markPaid($team->fresh());

        $this->actingAs($manager, 'api')
            ->get("/api/v1/my-teams/{$team->id}/receipt")
            ->assertOk();

        // Somebody else's team is not in their scope at all — 404, so the row's
        // existence is never confirmed.
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson("/api/v1/my-teams/{$team->id}/invoice")
            ->assertStatus(404);
    }

    /**
     * The organizer keeps the whole ticket price.
     *
     * The report used to headline "Pendapatan kotor" beside a "Biaya platform"
     * cut — both left over from the retired plan-tiered fee. The buyer's
     * gateway and service fees are charged on top of the price, so the
     * organizer's revenue is the price itself and there is nothing gross about
     * it. Asserting the figure alone would pass even with the deduction still
     * in place, so this also asserts the fee keys are gone.
     */
    public function test_the_ticket_report_counts_the_whole_price_as_revenue(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, ['qr_tickets' => 'true', 'payment_gateway' => 'true']);
        $event = $this->event($org);

        $order = $this->buy($org, $event, 50_000);
        app(TicketService::class)->markPaid($order->fresh());

        $finance = $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/ticket-report")
            ->assertOk()
            ->json('data.finance');

        // 2 × 50.000 — the fees the buyer paid on top are not part of it.
        $this->assertEquals(100_000, $finance['revenue']);
        $this->assertGreaterThan(0, (float) $order->fresh()->gateway_fee, 'sanity: the buyer did pay a fee');

        $this->assertArrayNotHasKey('platform_fee', $finance);
        $this->assertArrayNotHasKey('gross_revenue', $finance);
    }

    /**
     * The organizer can read the documents they issued.
     *
     * Scoped by the event, not by the manager's session — that is what makes it
     * a separate route from /my-teams. Compared against an outsider's org to
     * prove the event scope actually binds: asserting the owner gets a 200
     * would pass just as well with no scoping at all.
     */
    public function test_the_organizer_can_read_the_registration_documents_they_issued(): void
    {
        PlatformSettings::put(['payment_gateway_enabled' => true], null);
        PlatformSettings::flush();

        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner, ['online_registration' => 'true', 'payment_gateway' => 'true']);
        $event = $this->event($org);
        $category = $event->categories()->create([
            'name' => 'Putra', 'slug' => 'putra-org', 'tournament_format' => 'league', 'registration_fee' => 75_000,
        ]);

        $team = Team::create([
            'event_id' => $event->id,
            'category_id' => $category->id,
            'name' => 'Elang',
            'contact_name' => 'Andi',
            'contact_phone' => '0811',
            'manager_user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'registered_at' => now(),
        ]);

        app(RegistrationService::class)->startPayment($team, 'va');
        app(RegistrationService::class)->markPaid($team->fresh());

        $base = "/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/{$team->id}";

        foreach (['invoice', 'receipt'] as $kind) {
            $this->actingAs($owner, 'api')
                ->get("{$base}/{$kind}")
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
        }

        // Another organizer's event is not theirs to read. 403 here rather than
        // the 404 /my-teams gives: the `tenant` middleware refuses at the
        // organization before the row is ever looked up.
        $outsider = User::factory()->create();
        $this->orgWithPlan($outsider);
        $this->actingAs($outsider, 'api')
            ->getJson("{$base}/invoice")
            ->assertStatus(403);
    }

    /**
     * A team entered offline by the organizer has no manager account, and the
     * teams table holds a phone number rather than an email. Settling it must
     * still work — the document exists, only the mail has nobody to go to.
     */
    public function test_a_team_without_a_manager_still_settles_and_gets_a_document(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner, ['online_registration' => 'true']);
        $event = $this->event($org);
        $category = $event->categories()->create([
            'name' => 'Putri', 'slug' => 'putri', 'tournament_format' => 'league', 'registration_fee' => 75_000,
        ]);

        $team = Team::create([
            'event_id' => $event->id,
            'category_id' => $category->id,
            'name' => 'Offline FC',
            'contact_name' => 'Panitia',
            'contact_phone' => '0812',
            'payment_amount' => 75_000,
            'payment_status' => 'unpaid',
            'invoice_number' => 'INV-R/2026/09/9999',
            'status' => 'pending',
            'registered_at' => now(),
        ]);

        app(RegistrationService::class)->markPaid($team);

        $this->assertNotNull($team->fresh()->receipt_number);
    }

    /**
     * Every row belongs to the same table.
     *
     * The fee rows were written as `@if` directives between the `|` rows, which
     * leaves a blank line where the directive was — markdown then ends the
     * table there, and everything after it (Total dibayar, Dibayar) rendered as
     * loose text beside a table that had already closed. Counting cells is what
     * catches that: asserting the labels are present passes either way.
     */
    public function test_mail_renders_every_row_inside_one_table(): void
    {
        $order = new TicketOrder([
            'quantity' => 2,
            'unit_price' => 50_000,
            'total_price' => 100_000,
            'gateway_fee' => 4440,
            'gateway_tax' => 440,
            'service_fee' => 1500,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
            'status' => 'paid',
        ]);
        $order->setRelation('event', new Event(['name' => 'Cup', 'location_name' => 'GOR']));
        $order->setRelation('category', new \App\Models\TicketCategory(['name' => 'Reguler']));

        $html = (new \App\Mail\TicketPurchasedMail($order))->render();

        // 9 label/value pairs: event, tanggal, lokasi, kategori, jumlah, harga,
        // biaya layanan, biaya gateway, PPN, total — a row that fell out of the
        // table stops being a cell.
        foreach (['Harga tiket', 'Biaya layanan', 'Biaya payment gateway', 'PPN', 'Total dibayar'] as $label) {
            // The label must be closed by </strong></td> with no other tag
            // between: outside a table it renders as a bare paragraph instead.
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($label, '/').'<\/strong><\/td>/',
                $html,
                "\"{$label}\" must render as a table cell, not as stray text after the table closed",
            );
        }
    }

    public function test_registration_mail_carries_both_documents(): void
    {
        PlatformSettings::put(['payment_gateway_enabled' => true], null);
        PlatformSettings::flush();

        $manager = User::factory()->create();
        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner, ['online_registration' => 'true', 'payment_gateway' => 'true']);
        $event = $this->event($org);
        $category = $event->categories()->create([
            'name' => 'Putra', 'slug' => 'putra-m', 'tournament_format' => 'league', 'registration_fee' => 75_000,
        ]);

        $team = Team::create([
            'event_id' => $event->id,
            'category_id' => $category->id,
            'name' => 'Rajawali',
            'contact_name' => 'Andi',
            'contact_phone' => '0811',
            'manager_user_id' => $manager->id,
            'status' => 'pending',
            'registered_at' => now(),
        ]);

        app(RegistrationService::class)->startPayment($team, 'va');
        app(RegistrationService::class)->markPaid($team->fresh());

        $mail = (new RegistrationPaid($team->fresh()->load('event')))->toMail($manager);
        $names = array_column($mail->rawAttachments, 'name');

        $this->assertCount(2, $names, 'invoice and receipt, in that order');
        $this->assertStringStartsWith('Invoice-', $names[0]);
        $this->assertStringStartsWith('Kwitansi-', $names[1]);

        foreach ($mail->rawAttachments as $attachment) {
            $this->assertStringStartsWith('%PDF', $attachment['data']);
        }
    }

    /**
     * Payments settled before the numbering existed still get documents.
     *
     * The money moved and is on record, so withholding a document does not
     * avoid inventing one — it denies one for a payment that really happened.
     * Compared against a free row in the same sweep: asserting only that the
     * paid one gets a number would pass while the free one silently got one
     * too.
     */
    public function test_backfill_documents_payments_that_predate_the_numbering(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner, ['online_registration' => 'true']);
        $event = $this->event($org);
        $category = $event->categories()->create([
            'name' => 'Lama', 'slug' => 'lama', 'tournament_format' => 'league', 'registration_fee' => 100_000,
        ]);

        $base = [
            'event_id' => $event->id,
            'category_id' => $category->id,
            'contact_name' => 'Andi',
            'contact_phone' => '0811',
            'status' => 'approved',
            'registered_at' => now()->subMonth(),
            'created_at' => now()->subMonth(),
            'paid_at' => now()->subMonth(),
        ];

        $paid = Team::create([...$base, 'name' => 'Lunas Lama', 'payment_amount' => 100_000, 'payment_status' => 'paid']);
        $free = Team::create([...$base, 'name' => 'Gratis Lama', 'payment_amount' => 0, 'payment_status' => 'paid']);
        $unpaid = Team::create([...$base, 'name' => 'Belum Lunas', 'payment_amount' => 100_000, 'payment_status' => 'unpaid', 'paid_at' => null]);

        // The rows land through the model, so clear what markPaid() would have
        // written and replay the state these predate. created_at is set here
        // too: Eloquent stamps it on create regardless of the attribute, and
        // the backfill reads it to decide which month the number belongs to.
        Team::whereIn('id', [$paid->id, $free->id, $unpaid->id])->update([
            'invoice_number' => null,
            'receipt_number' => null,
            'created_at' => now()->subMonth(),
        ]);

        $this->runBackfill();

        $paid->refresh();
        $this->assertNotNull($paid->invoice_number);
        $this->assertNotNull($paid->receipt_number);
        // Numbered in the month it belongs to, not today.
        $this->assertStringContainsString(now()->subMonth()->format('Y/m'), $paid->invoice_number);

        $this->assertNull($free->refresh()->invoice_number, 'a free entry has no bill to document');

        // A bill without a payment: invoice yes, receipt no.
        $unpaid->refresh();
        $this->assertNotNull($unpaid->invoice_number);
        $this->assertNull($unpaid->receipt_number);

        // Idempotent — running it again must not renumber anything.
        $before = $paid->invoice_number;
        $this->runBackfill();
        $this->assertSame($before, $paid->fresh()->invoice_number);
    }

    private function runBackfill(): void
    {
        (require base_path('database/migrations/2026_09_14_110000_backfill_participant_document_numbers.php'))->up();
    }

    /** The view data ParticipantDocumentService builds, for asserting on rendered HTML. */
    private function viewData(TicketOrder $order): array
    {
        $service = new \ReflectionMethod(\App\Services\ParticipantDocumentService::class, 'subject');

        return [
            'order' => $order->load('event.organization', 'category'),
            'issuer' => config('billing'),
            // Mirrors what the service decides, rather than pinning it: hard-
            // coding false here would hide exactly the bug these tests check.
            'taxSplit' => (float) $order->gateway_tax > 0,
            'bank' => null,
            'money' => fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'),
            'date' => fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d F Y') : '—',
            ...$service->invoke(app(\App\Services\ParticipantDocumentService::class), $order),
        ];
    }
}
