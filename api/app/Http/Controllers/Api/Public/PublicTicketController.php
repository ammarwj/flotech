<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\PurchaseTicketRequest;
use App\Http\Resources\TicketCategoryResource;
use App\Http\Resources\TicketOrderResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketOrder;
use App\Services\MidtransService;
use App\Services\PlanGate;
use App\Services\TicketService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PublicTicketController extends Controller
{
    public function __construct(
        protected PlanGate $gate,
        protected TicketService $tickets,
        protected MidtransService $midtrans,
    ) {}

    /**
     * Ticket categories on sale for a public event.
     */
    public function categories(string $orgSlug, string $eventSlug): JsonResponse
    {
        $event = $this->resolve($orgSlug, $eventSlug);

        $categories = $event->ticketCategories()
            ->where('is_active', true)
            ->orderBy('price')
            ->get();

        return ApiResponse::success(TicketCategoryResource::collection($categories));
    }

    /**
     * Buy tickets: reserve quota, issue tickets and start a Midtrans payment.
     * Without Midtrans credentials the order is auto-paid (dev convenience).
     */
    public function purchase(PurchaseTicketRequest $request, string $orgSlug, string $eventSlug): JsonResponse
    {
        $event = $this->resolve($orgSlug, $eventSlug);
        $org = $event->organization;

        if (! $this->gate->allows($org, 'qr_tickets')) {
            return ApiResponse::error('Penjualan tiket tidak tersedia untuk event ini.', null, 422);
        }

        $data = $request->validated();

        $category = $event->ticketCategories()->find($data['ticket_category_id']);
        if (! $category) {
            return ApiResponse::error('Kategori tiket tidak ditemukan.', null, 404);
        }

        if (! $category->isOnSale()) {
            return ApiResponse::error('Kategori tiket ini sedang tidak dijual.', null, 422);
        }

        $remaining = $category->remaining();
        if ($remaining !== null && $remaining < $data['quantity']) {
            return ApiResponse::error('Sisa tiket tidak mencukupi.', null, 422);
        }

        $total = (float) $category->price * (int) $data['quantity'];
        $platformFee = $this->tickets->platformFee($org, $total);
        $orderId = 'TIX-'.Str::upper(Str::random(10));

        $order = $this->tickets->purchase(
            $category,
            [
                'buyer_name' => $data['buyer_name'],
                'buyer_email' => $data['buyer_email'],
                'buyer_phone' => $data['buyer_phone'] ?? null,
                'quantity' => $data['quantity'],
            ],
            $data['holder_names'] ?? [],
            $platformFee,
            $orderId,
            auth('api')->id(),
        );

        $snap = $this->midtrans->createSnapTransaction(
            ['order_id' => $orderId, 'gross_amount' => (int) round($total)],
            ['first_name' => $data['buyer_name'], 'email' => $data['buyer_email']],
        );

        if ($snap['token']) {
            $order->update(['midtrans_token' => $snap['token']]);
        }

        // Free tickets or no gateway configured — settle immediately.
        if ($snap['mock'] || $total <= 0) {
            $this->tickets->markPaid($order);
        }

        return ApiResponse::success([
            'order' => new TicketOrderResource($order->fresh()->load(['category', 'event', 'tickets.category'])),
            'snap_token' => $snap['token'],
            'redirect_url' => $snap['redirect_url'],
            'mock' => $snap['mock'] || $total <= 0,
        ], 'Pesanan tiket dibuat', 201);
    }

    /**
     * Fetch an order + its e-tickets (used by the public e-ticket page).
     */
    public function order(string $order): JsonResponse
    {
        $ticketOrder = TicketOrder::with(['category', 'event', 'tickets.category'])->findOrFail($order);

        return ApiResponse::success(new TicketOrderResource($ticketOrder));
    }

    /**
     * Resolve a published event by org + event slug (404 for drafts).
     */
    protected function resolve(string $orgSlug, string $eventSlug): Event
    {
        $org = Organization::where('slug', $orgSlug)->firstOrFail();

        return $org->events()
            ->where('slug', $eventSlug)
            ->where('status', '!=', 'draft')
            ->firstOrFail();
    }
}
