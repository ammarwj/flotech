<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\RefundRequest;
use App\Models\Team;
use App\Models\TicketOrder;
use App\Services\RefundService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-wide view of collected payments, plus the refund action.
 *
 * Refunding here voids the order and reverses the organizer's wallet credit.
 * It does NOT move money back to the buyer — that must also be done in the
 * Midtrans dashboard.
 *
 * `amount` is the price the organizer sells at and gets credited; `gateway_fee`
 * and `service_fee` are the buyer's own surcharge on top, and `gross_amount` is
 * what Midtrans charged. `platform_fee` is the retired plan-tiered cut — always
 * 0 on new gateway orders, kept because old rows still carry real values.
 */
class RefundController extends Controller
{
    public function __construct(protected RefundService $refunds) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'paid');

        $orders = TicketOrder::with('event.organization')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (TicketOrder $order) => [
                'id' => $order->id,
                'kind' => 'ticket_order',
                'reference' => $order->midtrans_order_id,
                'organization_name' => $order->event?->organization?->name,
                'event_name' => $order->event?->name,
                'payer' => $order->buyer_name,
                'amount' => (float) $order->total_price,
                'platform_fee' => (float) $order->platform_fee,
                'payment_method' => $order->payment_method,
                'payment_channel' => $order->payment_channel,
                'gateway_fee' => (float) $order->gateway_fee,
                'service_fee' => (float) $order->service_fee,
                'gross_amount' => $order->gross_amount,
                'status' => $order->status,
                'paid_at' => $order->paid_at,
            ]);

        $teams = Team::with('event.organization')
            ->where('payment_amount', '>', 0)
            ->when($status !== 'all', fn ($q) => $q->where('payment_status', $status))
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Team $team) => [
                'id' => $team->id,
                'kind' => 'team',
                'reference' => $team->midtrans_order_id,
                'organization_name' => $team->event?->organization?->name,
                'event_name' => $team->event?->name,
                'payer' => $team->name,
                'amount' => (float) $team->payment_amount,
                'platform_fee' => (float) $team->platform_fee,
                'payment_method' => $team->payment_method,
                'payment_channel' => $team->payment_channel,
                'gateway_fee' => (float) $team->gateway_fee,
                'service_fee' => (float) $team->service_fee,
                'gross_amount' => $team->gross_amount,
                'status' => $team->payment_status,
                'paid_at' => $team->paid_at,
            ]);

        $items = $orders->concat($teams)
            ->sortByDesc(fn ($row) => $row['paid_at'])
            ->values();

        return ApiResponse::success($items);
    }

    public function refundTicketOrder(RefundRequest $request, string $ticketOrder): JsonResponse
    {
        $this->refunds->refundTicketOrder(
            TicketOrder::findOrFail($ticketOrder),
            auth('api')->user(),
            $request->validated('reason'),
        );

        return ApiResponse::success(null, 'Pesanan tiket direfund. Jangan lupa refund juga di dashboard Midtrans.');
    }

    public function refundTeam(RefundRequest $request, string $team): JsonResponse
    {
        $this->refunds->refundTeam(
            Team::findOrFail($team),
            auth('api')->user(),
            $request->validated('reason'),
        );

        return ApiResponse::success(null, 'Pendaftaran direfund. Jangan lupa refund juga di dashboard Midtrans.');
    }
}
