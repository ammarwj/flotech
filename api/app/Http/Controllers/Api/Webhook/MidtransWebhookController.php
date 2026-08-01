<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Controller;
use App\Models\EventPlanOrder;
use App\Models\Team;
use App\Models\TicketOrder;
use App\Services\MidtransService;
use App\Services\RegistrationService;
use App\Services\EventPlanOrderService;
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
     */
    public function handle(Request $request): JsonResponse
    {
        $orderId = (string) $request->input('order_id');
        $paymentType = $request->input('payment_type');
        $statusCode = (string) $request->input('status_code');
        $grossAmount = (string) $request->input('gross_amount');
        $signature = (string) $request->input('signature_key');
        $transactionStatus = (string) $request->input('transaction_status');

        if (! $this->midtrans->isValidSignature($orderId, $statusCode, $grossAmount, $signature)) {
            return ApiResponse::error('Signature tidak valid.', null, 403);
        }

        return match (true) {
            Str::startsWith($orderId, 'TIX-') => $this->handleTicket($orderId, $transactionStatus),
            Str::startsWith($orderId, 'REG-') => $this->handleRegistration($orderId, $transactionStatus),
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

    protected function handleTicket(string $orderId, string $status): JsonResponse
    {
        $order = TicketOrder::where('midtrans_order_id', $orderId)->first();
        if (! $order) {
            return ApiResponse::error('Pesanan tiket tidak ditemukan.', null, 404);
        }

        match ($status) {
            'capture', 'settlement' => $this->tickets->markPaid($order),
            'deny', 'cancel', 'expire' => $this->tickets->cancel($order),
            default => null,
        };

        return ApiResponse::success(null, 'Webhook diproses');
    }

    protected function handleRegistration(string $orderId, string $status): JsonResponse
    {
        $team = Team::where('midtrans_order_id', $orderId)->first();
        if (! $team) {
            return ApiResponse::error('Pendaftaran tim tidak ditemukan.', null, 404);
        }

        match ($status) {
            'capture', 'settlement' => $this->registration->markPaid($team),
            default => null,
        };

        return ApiResponse::success(null, 'Webhook diproses');
    }
}
