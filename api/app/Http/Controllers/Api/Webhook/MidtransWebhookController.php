<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\TicketOrder;
use App\Services\MidtransService;
use App\Services\TicketService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MidtransWebhookController extends Controller
{
    public function __construct(
        protected MidtransService $midtrans,
        protected SubscriptionController $subscriptions,
        protected TicketService $tickets,
    ) {}

    /**
     * Handle Midtrans payment notification (HTTP callback). Routes by the
     * order-id prefix: SUB- = subscription, TIX- = ticket order.
     */
    public function handle(Request $request): JsonResponse
    {
        $orderId = (string) $request->input('order_id');
        $statusCode = (string) $request->input('status_code');
        $grossAmount = (string) $request->input('gross_amount');
        $signature = (string) $request->input('signature_key');
        $transactionStatus = (string) $request->input('transaction_status');

        if (! $this->midtrans->isValidSignature($orderId, $statusCode, $grossAmount, $signature)) {
            return ApiResponse::error('Signature tidak valid.', null, 403);
        }

        return match (true) {
            Str::startsWith($orderId, 'TIX-') => $this->handleTicket($orderId, $transactionStatus),
            default => $this->handleSubscription($orderId, $transactionStatus),
        };
    }

    protected function handleSubscription(string $orderId, string $status): JsonResponse
    {
        $subscription = Subscription::where('midtrans_order_id', $orderId)->first();
        if (! $subscription) {
            return ApiResponse::error('Subscription tidak ditemukan.', null, 404);
        }

        match ($status) {
            'capture', 'settlement' => $this->subscriptions->activate($subscription),
            'pending' => $subscription->update(['status' => 'past_due']),
            'deny', 'cancel', 'expire' => $subscription->update(['status' => 'cancelled']),
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
}
