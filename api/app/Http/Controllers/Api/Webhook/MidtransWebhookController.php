<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Controller;
use App\Models\EventPlanOrder;
use App\Models\RaceEntry;
use App\Models\Team;
use App\Models\TicketOrder;
use App\Services\EventPlanOrderService;
use App\Services\MidtransService;
use App\Services\RaceEntryService;
use App\Services\RegistrationService;
use App\Services\TicketService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MidtransWebhookController extends Controller
{
    public function __construct(
        protected MidtransService $midtrans,
        protected EventPlanOrderService $orders,
        protected TicketService $tickets,
        protected RegistrationService $registration,
        protected RaceEntryService $raceEntries,
    ) {}

    /**
     * Handle Midtrans payment notification (HTTP callback). Routes by the
     * order-id prefix: TIX- = ticket order, REG- = team registration fee,
     * RUN- = fun run entry, and everything else falls through to a plan order.
     *
     * Plan orders are matched by that `default` arm rather than by their own
     * prefix on purpose — see handlePlanOrder(). A new rail therefore only has
     * to add its own `Str::startsWith` arm; `match` evaluates `default` last
     * whatever position it is written in, so the arms cannot be shadowed by it
     * (unlike a `switch`). What a forgotten arm DOES cause is a silent
     * failure in the worst direction: the settlement lands in
     * handlePlanOrder(), finds no row, and answers Midtrans with a 404 — which
     * is retried a few times and then abandoned, leaving the row pending
     * forever with nothing logged as an error anywhere.
     *
     * Plan orders are matched by that `default` arm rather than by their own
     * prefix on purpose. They used to be minted as SUB- and are now minted as
     * PLN-; matching either prefix explicitly would strand every id of the
     * other kind still outstanding at Midtrans.
     *
     * `payment_type` is the instrument the payment actually settled with, and
     * all three arms pass it on. It is NOT a second spelling of the order's
     * `payment_channel`: that is the fee group the buyer picked *before* paying,
     * which the gateway fee was computed from, and one channel covers several
     * instruments — `va` settles as `bca_va` or `echannel`, `ewallet` as qris or
     * gopay. Only a settlement writes it, so an order that expired unpaid keeps
     * a null there.
     */
    public function handle(Request $request): JsonResponse
    {
        $orderId = (string) $request->input('order_id');
        $paymentType = MidtransService::paymentType($request->input('payment_type'));
        $statusCode = (string) $request->input('status_code');
        $grossAmount = (string) $request->input('gross_amount');
        $signature = (string) $request->input('signature_key');
        $transactionStatus = (string) $request->input('transaction_status');

        if (! $this->midtrans->isValidSignature($orderId, $statusCode, $grossAmount, $signature)) {
            return ApiResponse::error('Signature tidak valid.', null, 403);
        }

        return match (true) {
            Str::startsWith($orderId, 'TIX-') => $this->handleTicket($orderId, $transactionStatus, $paymentType),
            Str::startsWith($orderId, 'REG-') => $this->handleRegistration($orderId, $transactionStatus, $paymentType),
            default => $this->handlePlanOrder($orderId, $transactionStatus, $paymentType),
        };
    }

    /**
     * This is the `default` arm above, so it also catches order ids with no
     * prefix we recognise — which is safe: a manual plan order never gets a
     * `midtrans_order_id` at all, so the lookup below cannot reach one and
     * settle a plan Midtrans was never asked about.
     */
    protected function handlePlanOrder(string $orderId, string $status, ?string $paymentType = null): JsonResponse
    {
        $order = EventPlanOrder::where('midtrans_order_id', $orderId)->first();
        if (! $order) {
            return ApiResponse::error('Pembelian paket tidak ditemukan.', null, 404);
        }

        match ($status) {
            'capture', 'settlement' => $this->orders->activate($order, $paymentType),
            'pending' => $order->update(['status' => 'past_due']),
            'deny', 'cancel', 'expire' => $order->update(['status' => 'cancelled']),
            default => null,
        };

        return ApiResponse::success(null, 'Webhook diproses');
    }

    protected function handleTicket(string $orderId, string $status, ?string $paymentType = null): JsonResponse
    {
        $order = TicketOrder::where('midtrans_order_id', $orderId)->first();
        if (! $order) {
            return ApiResponse::error('Pesanan tiket tidak ditemukan.', null, 404);
        }

        match ($status) {
            'capture', 'settlement' => $this->tickets->markPaid($order, $paymentType),
            'deny', 'cancel', 'expire' => $this->tickets->cancel($order),
            default => null,
        };

        return ApiResponse::success(null, 'Webhook diproses');
    }

    protected function handleRaceEntry(string $orderId, string $status): JsonResponse
    {
        $entry = RaceEntry::where('midtrans_order_id', $orderId)->first();
        if (! $entry) {
            return ApiResponse::error('Entry lomba tidak ditemukan.', null, 404);
        }

        match ($status) {
            'capture', 'settlement' => $this->raceEntries->markPaid($entry),
            // Unlike handleRegistration above, the failure arm is wired: an
            // abandoned entry is holding a place in a quota, and the only other
            // thing that gives it back is the hourly sweep — which never
            // touches a gateway order, because this is what does.
            'deny', 'cancel', 'expire' => $this->raceEntries->cancel($entry),
            default => null,
        };

        return ApiResponse::success(null, 'Webhook diproses');
    }

    protected function handleRegistration(string $orderId, string $status, ?string $paymentType = null): JsonResponse
    {
        $team = Team::where('midtrans_order_id', $orderId)->first();
        if (! $team) {
            return ApiResponse::error('Pendaftaran tim tidak ditemukan.', null, 404);
        }

        match ($status) {
            'capture', 'settlement' => $this->registration->markPaid($team, $paymentType),
            default => null,
        };

        return ApiResponse::success(null, 'Webhook diproses');
    }
}
