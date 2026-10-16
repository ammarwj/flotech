<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Controller;
use App\Models\EventPlanOrder;
use App\Models\PaymentNotification;
use App\Models\Team;
use App\Models\TicketOrder;
use App\Services\EventPlanOrderService;
use App\Services\MidtransService;
use App\Services\PaymentNotificationService;
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
        protected PaymentNotificationService $notifications,
    ) {}

    /**
     * Handle Midtrans payment notification (HTTP callback). Routes by the
     * order-id prefix: TIX- = ticket order, REG- = team registration fee, and
     * everything else falls through to a plan order.
     *
     * Plan orders are matched by that `default` arm rather than by their own
     * prefix on purpose. They used to be minted as SUB- and are now minted as
     * PLN-; matching either prefix explicitly would strand every id of the
     * other kind still outstanding at Midtrans.
     *
     * Every notification is logged to `payment_notifications` **before** the
     * signature gate and before routing, so the two silent failures here leave
     * a trace: a rejected signature (which still returns 403 and moves
     * nothing), and an order id no arm can resolve — that one answers with a
     * 404, which Midtrans retries a few times and then abandons, leaving the
     * row pending forever with nothing logged as an error anywhere. The log row
     * is the only thing that can be grepped afterwards; `handled` is what tells
     * the two apart.
     */
    public function handle(Request $request): JsonResponse
    {
        $orderId = (string) $request->input('order_id');
        $paymentType = PaymentNotificationService::paymentType($request->input('payment_type'));
        $statusCode = (string) $request->input('status_code');
        $grossAmount = (string) $request->input('gross_amount');
        $signature = (string) $request->input('signature_key');
        $transactionStatus = (string) $request->input('transaction_status');

        $valid = $this->midtrans->isValidSignature($orderId, $statusCode, $grossAmount, $signature);

        $log = $this->notifications->record($request->all(), $valid);

        if (! $valid) {
            return ApiResponse::error('Signature tidak valid.', null, 403);
        }

        return match (true) {
            Str::startsWith($orderId, 'TIX-') => $this->handleTicket($orderId, $transactionStatus, $paymentType, $log),
            Str::startsWith($orderId, 'REG-') => $this->handleRegistration($orderId, $transactionStatus, $paymentType, $log),
            default => $this->handlePlanOrder($orderId, $transactionStatus, $paymentType, $log),
        };
    }

    /**
     * This is the `default` arm above, so it also catches order ids with no
     * prefix we recognise — which is safe: a manual plan order never gets a
     * `midtrans_order_id` at all, so the lookup below cannot reach one and
     * settle a plan Midtrans was never asked about.
     */
    protected function handlePlanOrder(string $orderId, string $status, ?string $paymentType = null, ?PaymentNotification $log = null): JsonResponse
    {
        $order = EventPlanOrder::where('midtrans_order_id', $orderId)->first();
        if (! $order) {
            return ApiResponse::error('Pembelian paket tidak ditemukan.', null, 404);
        }

        $this->notifications->markHandled($log, 'event_plan_order', $order->id);

        match ($status) {
            'capture', 'settlement' => $this->orders->activate($order, $paymentType),
            'pending' => $order->update(['status' => 'past_due']),
            'deny', 'cancel', 'expire' => $order->update(['status' => 'cancelled']),
            default => null,
        };

        return ApiResponse::success(null, 'Webhook diproses');
    }

    protected function handleTicket(string $orderId, string $status, ?string $paymentType = null, ?PaymentNotification $log = null): JsonResponse
    {
        $order = TicketOrder::where('midtrans_order_id', $orderId)->first();
        if (! $order) {
            return ApiResponse::error('Pesanan tiket tidak ditemukan.', null, 404);
        }

        $this->notifications->markHandled($log, 'ticket_order', $order->id);

        match ($status) {
            'capture', 'settlement' => $this->tickets->markPaid($order, $paymentType),
            'deny', 'cancel', 'expire' => $this->tickets->cancel($order),
            default => null,
        };

        return ApiResponse::success(null, 'Webhook diproses');
    }

    protected function handleRegistration(string $orderId, string $status, ?string $paymentType = null, ?PaymentNotification $log = null): JsonResponse
    {
        $team = Team::where('midtrans_order_id', $orderId)->first();
        if (! $team) {
            return ApiResponse::error('Pendaftaran tim tidak ditemukan.', null, 404);
        }

        $this->notifications->markHandled($log, 'team', $team->id);

        match ($status) {
            'capture', 'settlement' => $this->registration->markPaid($team, $paymentType),
            default => null,
        };

        return ApiResponse::success(null, 'Webhook diproses');
    }
}
