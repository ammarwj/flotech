<?php

namespace Tests\Feature;

use App\Mail\TicketPurchasedMail;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\TicketCategory;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\ParticipantDocumentService;
use App\Services\PlatformSettings;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Per-day ticketing: an event running 14–16 Nov sells access by the day.
 *
 * Every case here is written as a *comparison*, because almost none of these
 * rules can be proved by looking at one outcome:
 *
 * - asserting a per-day order bills correctly says nothing about whether plain
 *   categories still behave the way they did yesterday;
 * - asserting a date's remaining count dropped says nothing about whether it
 *   dropped on the dates nobody bought;
 * - asserting a ticket scans says nothing about whether it would also scan on
 *   the wrong day.
 */
class TicketDayTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function orgWithTickets(User $owner, array $extra = []): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);
        // `payment_gateway` as well: most cases here buy priced tickets, and
        // the rail is what makes a paid order possible at all.
        foreach (['qr_tickets' => 'true', 'payment_gateway' => 'true'] + $extra as $key => $value) {
            $plan->features()->create(['feature_key' => $key, 'value' => $value]);
        }

        $this->testPlan = $plan;

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    /** A three-day event: 14–16 November. */
    private function event(Organization $org): Event
    {
        return $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Cup', 'slug' => 'cup-'.uniqid(), 'sport_type' => 'futsal',
            'tournament_format' => 'league', 'status' => 'open',
            'start_date' => '2026-11-14', 'end_date' => '2026-11-16',
            'timezone' => 'Asia/Jakarta',
        ]);
    }

    /**
     * @param  list<string>  $dates
     */
    private function category(Event $event, string $mode, array $dates = [], array $attrs = []): TicketCategory
    {
        $category = $event->ticketCategories()->create($attrs + [
            'name' => 'Kategori '.$mode,
            'day_mode' => $mode,
            'price' => 40000,
            'is_active' => true,
        ]);

        foreach ($dates as $date) {
            $category->days()->create(['event_date' => $date]);
        }

        return $category->fresh()->load('days');
    }

    /**
     * A purchase as a buyer makes it. `payment_channel` is sent whenever the
     * category costs anything — the controller requires it on the gateway rail,
     * and sending it unconditionally is harmless for a free one.
     */
    private function buy(Organization $org, Event $event, TicketCategory $category, int $seats, array $dates = []): TestResponse
    {
        return $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", array_filter([
            'ticket_category_id' => $category->id,
            'quantity' => $seats,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
            'payment_channel' => 'va',
            'dates' => $dates === [] ? null : $dates,
        ], fn ($v) => $v !== null));
    }

    /**
     * The whole feature in one table. Asserting `per_day` alone would stay
     * green with `none` quietly changed, and asserting the QR count alone would
     * stay green with a pass holder billed three times.
     */
    public function test_three_day_modes_bill_and_issue_differently(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $days = ['2026-11-14', '2026-11-15', '2026-11-16'];

        $plain = $this->category($event, 'none');
        $perDay = $this->category($event, 'per_day', $days);
        $pass = $this->category($event, 'pass', $days, ['price' => 100000]);

        $plainOrder = TicketOrder::findOrFail($this->buy($org, $event, $plain, 2)->assertCreated()->json('data.order.id'));
        $perDayOrder = TicketOrder::findOrFail($this->buy($org, $event, $perDay, 2, $days)->assertCreated()->json('data.order.id'));
        $passOrder = TicketOrder::findOrFail($this->buy($org, $event, $pass, 2)->assertCreated()->json('data.order.id'));

        // Paid units: 2 seats, 2 seats × 3 days, 2 seats once.
        $this->assertSame(2, $plainOrder->quantity);
        $this->assertSame(6, $perDayOrder->quantity);
        $this->assertSame(2, $passOrder->quantity);

        // Seats are people in all three.
        $this->assertSame(2, $plainOrder->seats);
        $this->assertSame(2, $perDayOrder->seats);
        $this->assertSame(2, $passOrder->seats);

        $this->assertEqualsWithDelta(80_000.0, (float) $plainOrder->total_price, 0.0001);
        $this->assertEqualsWithDelta(240_000.0, (float) $perDayOrder->total_price, 0.0001);
        // The pass is the point: three days, one price each seat.
        $this->assertEqualsWithDelta(200_000.0, (float) $passOrder->total_price, 0.0001);

        // QRs: one per seat per day. The pass is the only row where this
        // differs from `quantity`.
        $this->assertSame(2, $plainOrder->tickets()->count());
        $this->assertSame(6, $perDayOrder->tickets()->count());
        $this->assertSame(6, $passOrder->tickets()->count());

        // A plain category's tickets carry no date at all — that null is what
        // keeps the scanner from comparing anything for them.
        $this->assertSame(0, $plainOrder->tickets()->whereNotNull('event_date')->count());
        $this->assertNull($plainOrder->event_dates);
        $this->assertSame($days, $perDayOrder->event_dates);
        $this->assertSame($days, $passOrder->event_dates);
    }

    /**
     * Capacity is the venue's, per date. Buying Sunday must leave Saturday
     * exactly as it was — asserting only that the bought date dropped would
     * stay green with a single global counter.
     */
    public function test_buying_one_day_leaves_the_other_days_untouched(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event, 'per_day', ['2026-11-14', '2026-11-15', '2026-11-16'], ['quota' => 10]);

        $this->buy($org, $event, $category, 2, ['2026-11-15'])->assertCreated();

        $category = $category->fresh()->load('days');

        $this->assertSame(10, $category->remainingOn('2026-11-14'));
        $this->assertSame(8, $category->remainingOn('2026-11-15'));
        $this->assertSame(10, $category->remainingOn('2026-11-16'));
    }

    /** A pass holder occupies a seat every day, so every date pays for it. */
    public function test_a_pass_takes_a_seat_on_every_day_it_covers(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event, 'pass', ['2026-11-14', '2026-11-15', '2026-11-16'], ['quota' => 10]);

        $this->buy($org, $event, $category, 2)->assertCreated();

        $category = $category->fresh()->load('days');

        $this->assertSame(8, $category->remainingOn('2026-11-14'));
        $this->assertSame(8, $category->remainingOn('2026-11-15'));
        $this->assertSame(8, $category->remainingOn('2026-11-16'));

        // And only one paid unit per seat, however many seats it reserved.
        $this->assertSame(2, $category->sold);
    }

    /**
     * One ticket, two days. Asserting only that the right day works would stay
     * green with no date guard at all.
     */
    public function test_a_ticket_scans_on_its_own_day_and_is_refused_on_another(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event, 'per_day', ['2026-11-15'], ['price' => 0]);

        $orderId = $this->buy($org, $event, $category, 1, ['2026-11-15'])->assertCreated()->json('data.order.id');
        $qr = $this->getJson("/api/v1/ticket-orders/{$orderId}")->json('data.tickets.0.qr_code');

        // The 14th: not this ticket's day.
        Carbon::setTestNow(Carbon::parse('2026-11-14 10:00', 'Asia/Jakarta'));
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/scan", ['qr_code' => $qr])
            ->assertStatus(409)
            ->assertJsonPath('errors.result', 'wrong_day')
            ->assertJsonPath('errors.ticket.event_date', '2026-11-15');

        // The 15th: the same code, now valid.
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00', 'Asia/Jakarta'));
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/scan", ['qr_code' => $qr])
            ->assertOk()
            ->assertJsonPath('data.result', 'valid');

        Carbon::setTestNow();
    }

    /**
     * "Today" is today where the event is. At 06:00 WIB it is still yesterday
     * in UTC, so an app-timezone comparison refuses every ticket scanned before
     * 07:00 — and only then, which is why no test that never names an hour
     * would catch it.
     */
    public function test_today_is_resolved_in_the_events_timezone_not_utc(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event, 'per_day', ['2026-11-14'], ['price' => 0]);

        $orderId = $this->buy($org, $event, $category, 1, ['2026-11-14'])->assertCreated()->json('data.order.id');
        $qr = $this->getJson("/api/v1/ticket-orders/{$orderId}")->json('data.tickets.0.qr_code');

        // 23:00 UTC on the 13th = 06:00 WIB on the 14th: gates are open.
        Carbon::setTestNow(Carbon::parse('2026-11-13 23:00', 'UTC'));

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/scan", ['qr_code' => $qr])
            ->assertOk()
            ->assertJsonPath('data.result', 'valid');

        Carbon::setTestNow();
    }

    /**
     * The regression net. A dateless ticket — what a `none` category issues and
     * what every ticket predating this feature carries — is admitted whatever
     * day it is scanned on.
     */
    public function test_a_dateless_ticket_is_admitted_on_any_day(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event, 'none', [], ['price' => 0]);

        $orderId = $this->buy($org, $event, $category, 1)->assertCreated()->json('data.order.id');
        $qr = $this->getJson("/api/v1/ticket-orders/{$orderId}")->json('data.tickets.0.qr_code');

        // A day outside the event's range entirely.
        Carbon::setTestNow(Carbon::parse('2027-01-20 10:00', 'Asia/Jakarta'));

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/scan", ['qr_code' => $qr])
            ->assertOk()
            ->assertJsonPath('data.result', 'valid')
            ->assertJsonPath('data.ticket.event_date', null);

        Carbon::setTestNow();
    }

    /**
     * Guard order. A ticket already used on its own day must keep reading
     * "sudah digunakan" — putting the date check first would relabel every
     * second scan as a wrong-day one and hide the reuse.
     */
    public function test_a_used_ticket_reads_used_not_wrong_day(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event, 'per_day', ['2026-11-15'], ['price' => 0]);

        $orderId = $this->buy($org, $event, $category, 1, ['2026-11-15'])->assertCreated()->json('data.order.id');
        $qr = $this->getJson("/api/v1/ticket-orders/{$orderId}")->json('data.tickets.0.qr_code');

        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00', 'Asia/Jakarta'));

        $scan = fn () => $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/scan", ['qr_code' => $qr]);

        $scan()->assertOk();
        $scan()->assertStatus(409)->assertJsonPath('errors.result', 'used');

        // And on a different day it is still the reuse that is reported: the
        // ticket is spent either way, and "salah hari" would send the holder
        // back to the queue to try again tomorrow.
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00', 'Asia/Jakarta'));
        $scan()->assertStatus(409)->assertJsonPath('errors.result', 'used');

        Carbon::setTestNow();
    }

    /**
     * Voiding gives back both counters, through *both* doors.
     *
     * refund() and cancel() are the two ways an order is voided, and they have
     * to release the same things — a copy that forgets the day rows leaves a
     * date reading sold out with nobody holding a ticket for it. Testing one
     * door would stay green with the other one broken, which is the whole
     * reason release() is written once.
     *
     * The second order is pushed back to `pending` by hand: with no Midtrans
     * key a priced order settles on the spot, and what is under test here is
     * release(), not how an order comes to be unpaid.
     */
    public function test_both_void_doors_release_the_day_seats_and_the_paid_units(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $days = ['2026-11-14', '2026-11-15'];
        $category = $this->category($event, 'per_day', $days, ['quota' => 10]);

        $tickets = app(TicketService::class);
        $fresh = fn () => $category->fresh()->load('days');

        $paid = TicketOrder::findOrFail($this->buy($org, $event, $category, 2, $days)->assertCreated()->json('data.order.id'));
        $this->assertSame(8, $fresh()->remainingOn('2026-11-14'));
        $this->assertSame(4, $fresh()->sold);

        $tickets->refund($paid);

        $this->assertSame(10, $fresh()->remainingOn('2026-11-14'));
        $this->assertSame(10, $fresh()->remainingOn('2026-11-15'));
        $this->assertSame(0, $fresh()->sold);
        $this->assertSame(0, $paid->tickets()->count());

        $unpaid = TicketOrder::findOrFail($this->buy($org, $event, $category->fresh()->load('days'), 1, ['2026-11-15'])->assertCreated()->json('data.order.id'));
        $unpaid->update(['status' => 'pending']);

        $this->assertSame(9, $fresh()->remainingOn('2026-11-15'));

        $tickets->cancel($unpaid->fresh());

        $this->assertSame(10, $fresh()->remainingOn('2026-11-15'));
        $this->assertSame(0, $fresh()->sold);
        $this->assertSame(0, $unpaid->tickets()->count());
    }

    /**
     * A sold-out Saturday must not be buyable through Sunday's spare seats.
     * Asserting the 422 alone would stay green with the order already written,
     * so the row count is compared too.
     */
    public function test_a_full_day_refuses_the_whole_order(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event, 'per_day', ['2026-11-14', '2026-11-15'], ['quota' => 2, 'price' => 0]);

        $this->buy($org, $event, $category, 2, ['2026-11-14'])->assertCreated();

        $before = TicketOrder::count();

        $this->buy($org, $event, $category->fresh()->load('days'), 1, ['2026-11-14', '2026-11-15'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sisa tiket untuk tanggal 2026-11-14 tidak mencukupi.');

        $this->assertSame($before, TicketOrder::count());
        // The day that *was* free is untouched: a refused order reserves nothing.
        $this->assertSame(2, $category->fresh()->load('days')->remainingOn('2026-11-15'));
    }

    /** A date the category never sold is its own refusal, not a quota one. */
    public function test_a_date_the_category_does_not_sell_is_refused(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);
        $category = $this->category($event, 'per_day', ['2026-11-14'], ['price' => 0]);

        $this->buy($org, $event, $category, 1, ['2026-11-16'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('dates', 'errors');

        $this->buy($org, $event, $category, 1, [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('dates', 'errors');
    }

    /**
     * The organizer's side. Four rules, and the pair that matters is the last
     * one: dropping a sold date is refused while *adding* a date is allowed —
     * asserting only the refusal would stay green with the whole list frozen.
     */
    public function test_day_sync_rules(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);

        $create = fn (array $payload) => $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/ticket-categories", $payload);

        // Outside the event's range.
        $create(['name' => 'A', 'price' => 0, 'day_mode' => 'per_day', 'dates' => ['2026-12-01']])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('dates', 'errors');

        // No dates at all on a day-selling category: zero days, not all of them.
        $create(['name' => 'B', 'price' => 0, 'day_mode' => 'per_day', 'dates' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('dates', 'errors');

        // Neither half-made category was left behind.
        $this->assertSame(0, $event->ticketCategories()->count());

        $id = $create(['name' => 'C', 'price' => 0, 'day_mode' => 'per_day', 'dates' => ['2026-11-14', '2026-11-15']])
            ->assertCreated()
            ->assertJsonCount(2, 'data.days')
            ->json('data.id');

        $category = TicketCategory::findOrFail($id);
        $this->buy($org, $event, $category->load('days'), 1, ['2026-11-14'])->assertCreated();

        $update = fn (array $payload) => $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/ticket-categories/{$id}", $payload);

        // Dropping the sold date: refused.
        $update(['dates' => ['2026-11-15']])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('dates', 'errors');

        // Adding one alongside it: allowed. This is the comparison — a lock,
        // not a freeze.
        $update(['dates' => ['2026-11-14', '2026-11-15', '2026-11-16']])
            ->assertOk()
            ->assertJsonCount(3, 'data.days');

        // The mode itself is frozen once anything sold…
        $update(['day_mode' => 'pass'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('day_mode', 'errors');

        // …but the name is not: an organizer mid-sale is not locked out of
        // their own form.
        $update(['name' => 'C2'])->assertOk()->assertJsonPath('data.name', 'C2');
    }

    /**
     * The buyer's paperwork names the days they bought, not the event's range.
     *
     * Compared against a dateless order in the same test: asserting the per-day
     * mail mentions a date would stay green with the event's own start date
     * printed there, which is the wrong day for anyone who bought only Sunday.
     */
    public function test_the_mail_and_the_invoice_name_the_days_bought(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithTickets($user);
        $event = $this->event($org);

        $perDay = $this->category($event, 'per_day', ['2026-11-14', '2026-11-15', '2026-11-16'], ['price' => 0]);
        $plain = $this->category($event, 'none', [], ['price' => 0]);

        $picked = TicketOrder::findOrFail(
            $this->buy($org, $event, $perDay, 2, ['2026-11-15', '2026-11-16'])->assertCreated()->json('data.order.id')
        );
        $dateless = TicketOrder::findOrFail(
            $this->buy($org, $event, $plain, 2)->assertCreated()->json('data.order.id')
        );

        $mail = (new TicketPurchasedMail($picked->load(['event', 'category'])))->render();

        // The two days bought, and NOT the 14th, which this buyer skipped.
        $this->assertStringContainsString('15 November 2026', $mail);
        $this->assertStringContainsString('16 November 2026', $mail);
        $this->assertStringNotContainsString('14 November 2026', $mail);
        // Seats and QRs, which differ here: 2 people, 4 tickets.
        $this->assertStringContainsString('2 orang · 4 tiket', $mail);

        // The dateless order keeps exactly the wording it had before: the event's
        // start date, and a plain ticket count.
        $plainMail = (new TicketPurchasedMail($dateless->load(['event', 'category'])))->render();
        $this->assertStringContainsString('14 November 2026', $plainMail);
        $this->assertStringContainsString('2 tiket', $plainMail);
        $this->assertStringNotContainsString('orang ·', $plainMail);

        // And the line the invoice/receipt prints. Read as a string rather than
        // out of the PDF: dompdf output is compressed, so asserting on the bytes
        // proves only that something rendered.
        $documents = app(ParticipantDocumentService::class);
        $note = $documents->ticketDaysNote($picked);
        $this->assertStringContainsString('15 Nov 2026', $note);
        $this->assertStringContainsString('16 Nov 2026', $note);
        $this->assertStringNotContainsString('14 Nov 2026', $note);
        // The dateless order prints nothing extra at all — not an empty "hari:".
        $this->assertSame('', $documents->ticketDaysNote($dateless));
    }

    /**
     * The fee is quoted per paid unit, so three days carry three of them. The
     * stored order is compared against the picker's own preview: a mismatch
     * here is a buyer quoted one number and charged another.
     */
    public function test_the_service_fee_counts_days_not_just_seats(): void
    {
        PlatformSettings::put(['ticket_service_fee_amount' => 2000], null);
        PlatformSettings::flush();

        $user = User::factory()->create();
        $org = $this->orgWithTickets($user, ['payment_gateway' => 'true']);
        $event = $this->event($org);
        $days = ['2026-11-14', '2026-11-15', '2026-11-16'];
        $category = $this->category($event, 'per_day', $days, ['price' => 50000]);

        $order = TicketOrder::findOrFail(
            $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
                'ticket_category_id' => $category->id,
                'quantity' => 2,
                'dates' => $days,
                'buyer_name' => 'Budi',
                'buyer_email' => 'budi@test.com',
                'payment_channel' => 'va',
            ])->assertCreated()->json('data.order.id')
        );

        // 2 seats × 3 days = 6 units × Rp 2.000.
        $this->assertEqualsWithDelta(12_000.0, (float) $order->service_fee, 0.0001);

        $preview = collect(
            $this->getJson('/api/v1/public/payment-channels?amount=300000&audience=ticket&units=6')
                ->assertOk()
                ->json('data')
        )->firstWhere('channel', 'va');

        $this->assertEqualsWithDelta((float) $order->service_fee, (float) $preview['service_fee'], 0.0001);
        $this->assertEqualsWithDelta((float) $order->gross_amount, (float) $preview['total'], 0.0001);
    }
}
