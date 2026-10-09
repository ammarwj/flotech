<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTicketDays;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\SellTicketRequest;
use App\Http\Resources\TicketOrderResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketOrder;
use App\Services\MidtransService;
use App\Services\PaymentFeeCalculator;
use App\Services\PaymentRails;
use App\Services\PlanGate;
use App\Services\TicketService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The box office: the organizer selling a ticket at the venue.
 *
 * Until now the only door that issued a ticket was the public shop, so a
 * walk-in buyer had to open their phone, find the event page and fill in the
 * same form — with a queue behind them. This is that form, staffed.
 *
 * Two rails, and which one a sale takes is *not* the till's choice — it is
 * PaymentRails::methodFor()'s answer about this event, the same answer the
 * public shop and the dashboard already read:
 *
 * - the event sells through the gateway, so the sale does too. A Midtrans
 *   payment is opened and its URL handed back; the dialog turns that into a QR
 *   the buyer scans with their own phone, so the staff's screen never leaves the
 *   dashboard and they can serve the next person. Settlement arrives from the
 *   webhook exactly as it does for an online sale.
 * - the event cannot sell online — a super admin has the gateway switched off,
 *   or the event itself takes manual transfers — so the box office takes cash.
 *   `onsite` is written paid on the spot, and the wallet is never credited
 *   because the money never passed through us. Same reasoning as the offline
 *   team entry in RegistrationController.
 *
 * Letting the staff pick would mean an event selling through the gateway could
 * take cash off the books, with no record on either side of what was collected.
 * It would also be a second reader of "global switch AND event rail", which is
 * exactly the drift `effective_payment_method` exists to prevent.
 *
 * `manual` has no place here: a box office cannot ask the person in front of it
 * to transfer and upload a receipt for an org admin to verify later. Where the
 * public shop answers an outage with a bank account, this one answers it with
 * cash.
 *
 * Checking in is a separate button rather than a flag on the order, because a
 * gateway sale is not paid when it is created — see checkInOrder().
 */
class TicketSaleController extends Controller
{
    use ResolvesTicketDays;

    public function __construct(
        protected PlanGate $gate,
        protected TicketService $tickets,
        protected MidtransService $midtrans,
        protected PaymentRails $rails,
        protected PaymentFeeCalculator $fees,
    ) {}

    /**
     * Sell tickets at the venue: reserve quota, issue the QRs, open payment.
     */
    public function store(SellTicketRequest $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        // 403 + `errors.feature`, unlike the public door's 422 for the same
        // gate: this is an organizer surface, and 403+feature is what
        // isPlanLimitError() picks up in the client. A visitor cannot act on
        // the difference between "not on sale" and "not in your plan"; the
        // organizer standing at the till can.
        if (! $this->gate->allows($event, 'qr_tickets')) {
            return ApiResponse::error(
                'Fitur tiket tidak termasuk dalam paket event ini.',
                ['feature' => 'qr_tickets'],
                403,
            );
        }

        $data = $request->validated();

        $category = $event->ticketCategories()->with('days')->find($data['ticket_category_id']);
        if (! $category) {
            return ApiResponse::error('Kategori tiket tidak ditemukan.', null, 404);
        }

        // `isOnSale()` is deliberately NOT checked here, the one place this door
        // diverges from the public one. A box office sells at the venue, which
        // is usually after the online window has closed — and the staff member
        // asking for the sale is the organizer. Quota still binds below:
        // ignoring the clock is not ignoring capacity.

        $seats = (int) $data['quantity'];

        $dates = $this->datesFor($category, $data['dates'] ?? null);
        if ($dates instanceof JsonResponse) {
            return $dates;
        }

        if ($denied = $this->ensureSeatsAvailable($category, $dates, $seats)) {
            return $denied;
        }

        $pricedUnits = $seats * $category->pricedDays($dates);
        $total = (float) $category->price * $pricedUnits;

        // The rail, derived — never sent. `methodFor()` already folds the global
        // switch together with the event's own choice, and it is the same
        // method EventResource publishes as `effective_payment_method`, so the
        // till and the buyer's checkout cannot disagree about this event.
        //
        // An event that sells by manual transfer becomes a *cash* box office,
        // not a manual one: the person is standing here, and there is nothing
        // for them to upload. That substitution is the whole mapping.
        $onsite = $this->rails->methodFor($event) === 'manual';

        $channel = null;
        $gatewayFee = 0.0;
        $gatewayTax = 0.0;
        $serviceFee = 0.0;
        $enabledPayments = [];

        if (! $onsite) {
            // Still asked, and still able to refuse: `methodFor()` answers
            // "which rail", while this answers "can this organizer collect on
            // it at all" — a plan without `payment_gateway` throws here. A free
            // ticket gets null and needs no rail.
            $this->rails->destinationFor($event, $total);

            if ($total > 0) {
                if (empty($data['payment_channel'])) {
                    return ApiResponse::error(
                        'Pilih metode pembayaran.',
                        ['payment_channel' => ['Metode pembayaran wajib dipilih.']],
                        422,
                    );
                }

                // Paid units, not seats: the platform's service fee is charged
                // per ticket, so a three-day basket carries three of them.
                $breakdown = $this->fees->forChannel(
                    $data['payment_channel'],
                    $total,
                    PaymentFeeCalculator::AUDIENCE_TICKET,
                    $pricedUnits,
                );
                $channel = $breakdown['channel'];
                $gatewayFee = $breakdown['gateway_fee'];
                $gatewayTax = $breakdown['gateway_tax'];
                $serviceFee = $breakdown['service_fee'];
                $enabledPayments = $breakdown['midtrans_payments'];
            }
        }

        // `TIX-` on both rails, including onsite. The prefix is what routes a
        // Midtrans callback (see MidtransWebhookController), and an onsite order
        // will never get one — but a stray callback finding an order that is
        // already `paid` is a no-op, markPaid() being idempotent, whereas any
        // other prefix falls through to the plan-order arm and 404s.
        $orderId = 'TIX-'.Str::upper(Str::random(10));

        $order = $this->tickets->purchase(
            $category,
            [
                'buyer_name' => $data['buyer_name'],
                'buyer_email' => $data['buyer_email'],
                'buyer_phone' => $data['buyer_phone'] ?? null,
                'quantity' => $pricedUnits,
            ],
            $data['holder_names'] ?? [],
            $orderId,
            // Not the staff member: `buyer_user_id` is whose dashboard the
            // order belongs to, and this ticket is not theirs.
            null,
            $onsite ? 'onsite' : 'gateway',
            // No deadline on either rail here. An onsite order is paid before
            // it exists, and a gateway one expires through Midtrans like every
            // other gateway order — `payment_deadline_at` drives the manual
            // sweep only (tickets:expire-manual).
            null,
            $channel,
            $gatewayFee,
            $serviceFee,
            $gatewayTax,
            $dates,
            $seats,
        );

        $snap = ['token' => null, 'redirect_url' => null, 'mock' => false];

        if (! $onsite) {
            $snap = $this->midtrans->createSnapTransaction(
                ['order_id' => $orderId, 'gross_amount' => (int) round($order->gross_amount)],
                ['first_name' => $data['buyer_name'], 'email' => $data['buyer_email']],
                rtrim((string) config('app.frontend_url'), '/').'/tickets/'.$order->id,
                $enabledPayments,
            );

            if ($snap['token']) {
                $order->update(['midtrans_token' => $snap['token']]);
            }
        }

        // An onsite sale settles because the money is already in hand — that is
        // what the rail means. For a gateway sale, `mock` still means "no server
        // key configured", not "paid", and only a free ticket settles itself.
        $settled = $onsite || $snap['mock'] || $total <= 0;
        if ($settled) {
            $this->tickets->markPaid($order);
        }

        return ApiResponse::success([
            'order' => new TicketOrderResource(
                $order->fresh()->load(['category', 'event.organization.bankAccounts', 'tickets.category']),
            ),
            'snap_token' => $snap['token'],
            'redirect_url' => $snap['redirect_url'],
            'rail' => $onsite ? 'onsite' : 'gateway',
            'settled' => $settled,
        ], 'Pesanan tiket dibuat', 201);
    }

    /**
     * Check in a paid order's tickets without scanning each QR.
     *
     * The holder is standing at the till, so walking them through the scanner
     * to read a code off a screen they are already in front of is theatre. The
     * day rule is the scanner's, enforced in TicketService::checkInOrder(): a
     * pass sold on day one is admitted for day one only.
     *
     * Separate from the sale because a gateway sale is not paid when it is
     * created. The dialog offers this the moment an onsite sale lands, and the
     * buyer list offers it for a gateway order that settled later.
     */
    public function checkInOrder(Request $request, string $organization, string $ticketOrder): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        // Resolved through the org's events, never TicketOrder::findOrFail():
        // the bare lookup crosses tenants, and an id is all a caller needs.
        $order = TicketOrder::query()
            ->whereIn('event_id', $org->events()->select('id'))
            ->with(['event', 'category', 'tickets.category'])
            ->findOrFail($ticketOrder);

        if ($order->status !== 'paid') {
            return ApiResponse::error('Pesanan ini belum lunas.', null, 422);
        }

        $checkedIn = $this->tickets->checkInOrder($order, auth('api')->id());

        if ($checkedIn === 0) {
            // Two different situations reach here and the staff can act on the
            // difference: every ticket is already in, or none of them is for
            // today. Naming the days is what tells them apart.
            $dates = $order->event_dates;

            return ApiResponse::error(
                $dates
                    ? 'Tidak ada tiket yang berlaku hari ini. Pesanan ini untuk tanggal '.implode(', ', $dates).'.'
                    : 'Semua tiket pesanan ini sudah di-check-in.',
                null,
                422,
            );
        }

        return ApiResponse::success([
            'checked_in' => $checkedIn,
            'order' => new TicketOrderResource($order->fresh()->load(['category', 'tickets.category'])),
        ], 'Check-in berhasil');
    }

    /**
     * Resolve an event scoped to the current organization.
     */
    protected function findEvent(Request $request, string $eventId): Event
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org->events()->findOrFail($eventId);
    }
}
