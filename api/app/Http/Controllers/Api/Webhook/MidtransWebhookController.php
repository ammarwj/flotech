<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\MidtransService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MidtransWebhookController extends Controller
{
    public function __construct(
        protected MidtransService $midtrans,
        protected SubscriptionController $subscriptions,
    ) {}

    /**
     * Handle Midtrans payment notification (HTTP callback).
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

        $subscription = Subscription::where('midtrans_order_id', $orderId)->first();
        if (! $subscription) {
            return ApiResponse::error('Subscription tidak ditemukan.', null, 404);
        }

        match ($transactionStatus) {
            'capture', 'settlement' => $this->subscriptions->activate($subscription),
            'pending' => $subscription->update(['status' => 'past_due']),
            'deny', 'cancel', 'expire' => $subscription->update(['status' => 'cancelled']),
            default => null,
        };

        return ApiResponse::success(null, 'Webhook diproses');
    }
}
