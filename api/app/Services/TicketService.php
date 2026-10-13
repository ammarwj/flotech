<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Mail\TicketPurchasedMail;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketCategoryDay;
use App\Models\TicketOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ticket order lifecycle: platform-fee calculation, purchase (reserving quota
 * and issuing the individual QR tickets), settlement and cancellation.
 *
 * Tickets are issued at purchase time but only become valid for check-in once
 * their order is paid — see ScanController.
 */
class TicketService
{
    /**
     * The most seats one order may take, whatever the category's quota says.
     *
     * A loop guard, not a product cap — the same role as
     * `LineupService::MAX_PLAYERS`. What limits a sale is the quota: an
     * unlimited category is genuinely unlimited, and a party of 80 buys 80 in
     * one go. The ceiling is there because `purchase()` writes one ticket row
     * and one QR per seat *per day* inside a single transaction, so seats and
     * days multiply: 100 seats across a 60-day pass is already 6.000 rows. A
     * group past it checks out more than once, which is the right failure — the
     * refusal is a 422 on `quantity`, a field that is on screen.
     *
     * The ceiling is here rather than in the two FormRequests that enforce it
     * because two copies of a number nobody re-reads would drift, and the drift
     * would read as a quantity the public shop accepts and the box office
     * refuses.
     */
    public const MAX_SEATS_PER_ORDER = 100;

    /**
     * The most days one order may cover, the other half of the guard above.
     *
     * Enforced on the `dates` array by both ticket doors and by the category
     * form that decides which days exist at all.
     */
    public const MAX_DAYS_PER_ORDER = 60;

    /**
     * The most paid units one order can possibly come to — seats times days.
     *
     * **Derived, never typed**, and not interchangeable with the seat count
     * above however close the two numbers look. The fee preview is quoted paid
     * units, and it carried its own flat `max:100` — which a 34-seat order on a
     * three-day event already breaches. A cap on the preview that is tighter
     * than what the order path accepts is invisible from the server's side: the
     * picker simply renders no channels, and the buyer meets a form that cannot
     * be submitted and says nothing about why. Any second reader of this
     * ceiling asks for this constant, so that lowering the seat count lowers
     * this with it and can never leave it behind.
     */
    public const MAX_PAID_UNITS_PER_ORDER = self::MAX_SEATS_PER_ORDER * self::MAX_DAYS_PER_ORDER;

    public function __construct(
        protected PlanGate $gate,
        protected WalletService $wallet,
        protected DocumentNumberService $numbers,
        protected ParticipantDocumentService $documents,
    ) {}

    /** @param  'invoice'|'receipt'  $kind */
    protected function number(string $kind): string
    {
        return $this->numbers->next(
            TicketOrder::class,
            "{$kind}_number",
            config("billing.ticket_{$kind}_prefix"),
        );
    }

    /**
     * Create a pending order, reserve the quota and issue one QR ticket per
     * seat per day. Runs in a transaction so quota and tickets stay consistent.
     *
     * `$paymentMethod` is snapshotted rather than resolved later: the gateway
     * can be switched back on at any time, and an order taken during an outage
     * stays a manual one for the rest of its life. `$deadline` only applies to
     * manual orders — nothing else releases their reserved quota.
     *
     * `platform_fee` is always written 0: the column stays for historical
     * orders, but the fee it used to hold is now the buyer-paid gateway/service
     * fee below, computed by the caller from PaymentFeeCalculator.
     *
     * **`$buyer['quantity']` is paid units, `$seats` is people.** They are the
     * same number for everything except a pass, which is paid once and admitted
     * every day — see the migration's table. The caller works both out, because
     * it is the only place that knows the category's day mode. `$dates` empty
     * means a `none` category: one ticket per seat, no date on it, which is
     * exactly the code this method has always run.
     *
     * @param  array{buyer_name: string, buyer_email: string, buyer_phone?: string|null, quantity: int}  $buyer
     * @param  list<string|null>  $holderNames
     * @param  list<string>  $dates  `Y-m-d`, the days this order covers
     */
    public function purchase(TicketCategory $category, array $buyer, array $holderNames, string $orderId, ?string $userId, string $paymentMethod = 'gateway', ?Carbon $deadline = null, ?string $paymentChannel = null, float $gatewayFee = 0.0, float $serviceFee = 0.0, float $gatewayTax = 0.0, array $dates = [], ?int $seats = null): TicketOrder
    {
        $quantity = (int) $buyer['quantity'];
        $seats = $seats ?? $quantity;
        $unitPrice = (float) $category->price;

        return DB::transaction(function () use ($category, $buyer, $holderNames, $orderId, $userId, $quantity, $seats, $dates, $unitPrice, $paymentMethod, $deadline, $paymentChannel, $gatewayFee, $serviceFee, $gatewayTax) {
            $category->increment('sold', $quantity);
            // A pass bumps every date too: its holder occupies a seat each day.
            $this->shiftDays($category->id, $dates, $seats);

            $order = $category->orders()->create([
                'event_id' => $category->event_id,
                'buyer_user_id' => $userId,
                'buyer_name' => $buyer['buyer_name'],
                'buyer_email' => $buyer['buyer_email'],
                'buyer_phone' => $buyer['buyer_phone'] ?? null,
                'quantity' => $quantity,
                'seats' => $seats,
                // Null rather than [] for a `none` order: "this order has no
                // days" and "this order covers no days" are not the same claim.
                'event_dates' => $dates === [] ? null : array_values($dates),
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $quantity,
                // A free ticket has no bill, so it gets no document — a Rp 0
                // invoice claims a transaction that never happened.
                'invoice_number' => $unitPrice * $quantity > 0 ? $this->number('invoice') : null,
                'platform_fee' => 0,
                'status' => 'pending',
                'payment_method' => $paymentMethod,
                'payment_channel' => $paymentChannel,
                'gateway_fee' => $gatewayFee,
                // Already inside gateway_fee; stored so the PDF can show the
                // tax as its own line without recomputing a rate that moves.
                'gateway_tax' => $gatewayTax,
                'service_fee' => $serviceFee,
                'payment_deadline_at' => $deadline,
                'midtrans_order_id' => $orderId,
            ]);

            // One row per seat per day, so each day gets its own QR and its own
            // check-in. `[null]` is what makes a `none` category fall through
            // the same loop with no date — not a separate branch that could
            // drift from this one.
            foreach ($dates === [] ? [null] : $dates as $date) {
                for ($seat = 0; $seat < $seats; $seat++) {
                    $order->tickets()->create([
                        'ticket_category_id' => $category->id,
                        'event_id' => $category->event_id,
                        'event_date' => $date,
                        'qr_code' => 'TIX-' . Str::lower(Str::ulid()),
                        // Keyed by seat, not by row: a buyer's three days are
                        // three tickets belonging to the same person.
                        'holder_name' => $holderNames[$seat] ?? $buyer['buyer_name'],
                    ]);
                }
            }

            return $order;
        });
    }

    /**
     * Settle a paid order, credit the organizer's wallet and email the buyer
     * their e-ticket link. Idempotent — re-delivered webhooks are no-ops (the
     * early return is also what keeps the buyer from getting a second mail),
     * and the wallet guards itself besides.
     */
    public function markPaid(TicketOrder $order): void
    {
        if ($order->status === 'paid') {
            return;
        }

        DB::transaction(function () use ($order) {
            $order->update([
                'status' => 'paid',
                'paid_at' => Carbon::now(),
                // `??` keeps a re-delivered webhook from issuing a second
                // receipt for one payment; free orders never get one at all.
                'receipt_number' => $order->receipt_number
                    ?? ((float) $order->total_price > 0 ? $this->number('receipt') : null),
            ]);

            // A manual transfer went straight into the organizer's own bank
            // account, and box-office cash never left the staff's hand — the
            // money never passed through us, so crediting the wallet would make
            // their balance claim funds we are not holding. Same reasoning as
            // the offline team entry in RegistrationController.
            //
            // isOffPlatform(), not isManual(): `onsite` is the third rail and
            // reading `manual` alone here would credit the wallet for cash the
            // platform never touched, silently and with a green toast.
            if (! $order->isOffPlatform()) {
                $this->wallet->creditTicketOrder($order->load('event.organization'));
            }
        });

        $this->sendPurchaseConfirmation($order);
    }

    /**
     * Queue the buyer's confirmation mail. Deliberately swallows its own
     * errors: the payment is already settled, so a mail/queue hiccup must not
     * bubble up into the Midtrans webhook and provoke a retry.
     */
    protected function sendPurchaseConfirmation(TicketOrder $order): void
    {
        try {
            Mail::to($order->buyer_email)->queue(
                new TicketPurchasedMail($order->load(['event', 'category']))
            );
        } catch (Throwable $e) {
            Log::error('Gagal mengirim email konfirmasi tiket', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark one ticket used. The only place those three columns are written.
     *
     * Two doors check people in — the QR scanner at the gate and the box-office
     * dialog that just sold the ticket — and they must write the same thing.
     * Two copies would drift, and the drift would read as a ticket the scanner
     * calls fresh and the buyer list calls used. Same reasoning as release()
     * owning both void paths.
     */
    public function checkIn(Ticket $ticket, ?string $userId): void
    {
        $ticket->update([
            'is_used' => true,
            'used_at' => Carbon::now(),
            'used_by' => $userId,
        ]);
    }

    /**
     * Check in every ticket of an order that is valid *today*.
     *
     * A dateless order (`event_dates` null — what a `none` category issues, and
     * what every ticket predating per-day sales carries) admits all of its
     * tickets at once: there is no other day for them to be valid on.
     *
     * A dated order admits only today's rows. A three-day pass sold at the gate
     * on day one must not burn days two and three — the holder is standing here
     * now, not three times. "Today" is today in the *event's* zone, not UTC, for
     * the reason spelled out in ScanController: a WIB morning is still yesterday
     * in the app timezone, so every check-in before 07:00 would find nothing.
     *
     * Already-used rows are skipped rather than refused, so the count is what
     * this call actually changed — that is what lets the caller tell "nothing
     * applies today" from "all of it was already in".
     *
     * @return int how many tickets this call checked in
     */
    public function checkInOrder(TicketOrder $order, ?string $userId): int
    {
        $rows = $this->ticketsValidToday($order)->where('is_used', false)->get();

        foreach ($rows as $ticket) {
            $this->checkIn($ticket, $userId);
        }

        return $rows->count();
    }

    /**
     * The order's tickets that admit someone *today* — used or not.
     *
     * Extracted because the gate asks the same question twice and must get the
     * same answer: checkInOrder() narrows it to the unused rows it is about to
     * admit, and the scanner's "20 of 20" denominator counts all of them. A
     * second copy of "which day is this" would drift from this one, and the
     * drift would show as a party told 20 of 5 — the same class of bug as two
     * readers of `stage`.
     *
     * A dateless order (`event_dates` null — what a `none` category issues, and
     * what every ticket predating per-day sales carries) matches everything it
     * has: there is no other day for those rows to be valid on.
     */
    public function ticketsValidToday(TicketOrder $order): HasMany
    {
        $order->loadMissing('event');

        $tickets = $order->tickets();

        if ($order->event_dates !== null) {
            $today = Carbon::now($order->event?->timezone ?? config('app.timezone'))->toDateString();
            $tickets->whereDate('event_date', $today);
        }

        return $tickets;
    }

    /**
     * The buyer uploads their transfer receipt for an org admin to check.
     *
     * @throws PaymentException
     */
    public function submitProof(TicketOrder $order, string $proofUrl): void
    {
        // isManual(), not isOffPlatform(): a box-office order has no transfer to
        // document, so refusing one here is the right answer, not an oversight.
        if (! $order->isManual()) {
            throw new PaymentException('Pesanan ini tidak dibayar lewat transfer manual.');
        }

        if ($order->status !== 'pending') {
            throw new PaymentException('Pesanan ini sudah tidak menunggu pembayaran.');
        }

        $order->attachProof($proofUrl);
    }

    /**
     * Accept a manual transfer. markPaid() is what keeps this off the wallet —
     * the money went to the organizer's own account, not ours.
     *
     * @throws PaymentException
     */
    public function approveProof(TicketOrder $order, User $admin): void
    {
        if (! $order->isAwaitingVerification()) {
            throw new PaymentException('Tidak ada bukti pembayaran yang menunggu verifikasi.');
        }

        $order->markVerified($admin);
        $this->markPaid($order->fresh());
    }

    /**
     * @throws PaymentException
     */
    public function rejectProof(TicketOrder $order, string $reason, Carbon $deadline): void
    {
        if (! $order->isAwaitingVerification()) {
            throw new PaymentException('Tidak ada bukti pembayaran yang menunggu verifikasi.');
        }

        $order->rejectProof($reason, $deadline);
    }

    /**
     * Void a *paid* order: release its quota and tickets. The wallet reversal
     * is RefundService's job. `cancel()` can't be reused — it deliberately
     * refuses paid orders.
     */
    public function refund(TicketOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order->update(['status' => 'refunded']);
            $this->release($order);
        });
    }

    /**
     * Cancel an unpaid order: release its reserved quota and void its tickets.
     */
    public function cancel(TicketOrder $order, string $status = 'cancelled'): void
    {
        if (in_array($order->status, ['paid', 'cancelled', 'refunded'], true)) {
            return;
        }

        DB::transaction(function () use ($order, $status) {
            $order->update(['status' => $status]);
            $this->release($order);
        });
    }

    /**
     * Give back everything an order was holding.
     *
     * Written once because there are two doors that void an order and they must
     * release the same things: the category's paid-unit count, each day's seat
     * count, and the issued tickets. Two copies would drift, and the drift
     * would look like a date that reads sold out with nobody holding a ticket
     * for it — same reasoning as MatchResultService owning both result doors.
     */
    protected function release(TicketOrder $order): void
    {
        $order->category()->decrement('sold', $order->quantity);
        $this->shiftDays($order->ticket_category_id, $order->event_dates ?? [], -$order->seats);
        $order->tickets()->delete();
    }

    /**
     * Move each day's seat count by `$by` (negative to give seats back).
     *
     * Clamped at zero on the way down: a row that somehow went out of step
     * should read "empty", never a negative capacity that makes remaining()
     * larger than the quota.
     *
     * @param  list<string>  $dates
     */
    protected function shiftDays(string $categoryId, array $dates, int $by): void
    {
        if ($dates === [] || $by === 0) {
            return;
        }

        foreach ($dates as $date) {
            $day = TicketCategoryDay::where('ticket_category_id', $categoryId)
                ->where('event_date', $date)
                ->first();

            $day?->update(['sold' => max(0, $day->sold + $by)]);
        }
    }
}
