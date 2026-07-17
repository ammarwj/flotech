<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\PurchaseTicketRequest;
use App\Http\Resources\PublicBankAccountResource;
use App\Http\Resources\TicketCategoryResource;
use App\Http\Resources\TicketOrderResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketOrder;
use App\Services\MidtransService;
use App\Services\PaymentRails;
use App\Services\PlanGate;
use App\Services\TicketService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicTicketController extends Controller
{
    public function __construct(
        protected PlanGate $gate,
        protected TicketService $tickets,
        protected MidtransService $midtrans,
        protected PaymentRails $rails,
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
     * Buy tickets: reserve quota, issue tickets and start payment.
     *
     * Two rails. Normally Midtrans; while a super admin has the gateway switched
     * off, the buyer transfers straight to the organizer's bank and uploads
     * proof for an org admin to approve. Free tickets use neither.
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

        // Throws (422/403) when this organizer can't collect at all. A bank
        // account back means the gateway is off and the buyer transfers to the
        // organizer directly; null means Midtrans, or a free ticket.
        $bank = $this->rails->destinationFor($org, $total);
        $manual = $bank !== null;

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
            // Manual money never reaches us, so there is nothing to take a cut of.
            $manual ? 0.0 : $this->tickets->platformFee($org, $total),
            $orderId,
            auth('api')->id(),
            $manual ? 'manual' : 'gateway',
            $manual ? $this->rails->deadline() : null,
        );

        $snap = ['token' => null, 'redirect_url' => null, 'mock' => false];

        if (! $manual) {
            $snap = $this->midtrans->createSnapTransaction(
                ['order_id' => $orderId, 'gross_amount' => (int) round($total)],
                ['first_name' => $data['buyer_name'], 'email' => $data['buyer_email']],
                rtrim((string) config('app.frontend_url'), '/').'/tickets/'.$order->id,
            );

            if ($snap['token']) {
                $order->update(['midtrans_token' => $snap['token']]);
            }
        }

        // Free tickets, or dev with no Midtrans credentials, settle on the spot.
        // A manual order must never reach this: `mock` means "no server key",
        // not "paid", and settling one would issue tickets for money nobody sent.
        $settled = ! $manual && ($snap['mock'] || $total <= 0);
        if ($settled) {
            $this->tickets->markPaid($order);
        }

        return ApiResponse::success([
            'order' => new TicketOrderResource(
                $order->fresh()->load(['category', 'event.organization.bankAccounts', 'tickets.category']),
            ),
            'snap_token' => $snap['token'],
            'redirect_url' => $snap['redirect_url'],
            'mock' => $settled,
            'payment_method' => $manual ? 'manual' : 'gateway',
            'bank_account' => $bank ? new PublicBankAccountResource($bank) : null,
        ], 'Pesanan tiket dibuat', 201);
    }

    /**
     * Fetch an order + its e-tickets (used by the public e-ticket page).
     */
    public function order(string $order): JsonResponse
    {
        // The organization chain is what lets an unpaid manual order tell the
        // buyer where to transfer (see TicketOrderResource::payTo()).
        $ticketOrder = TicketOrder::with([
            'category',
            'event.organization.bankAccounts',
            'tickets.category',
        ])->findOrFail($order);

        return ApiResponse::success(new TicketOrderResource($ticketOrder));
    }

    /**
     * Upload the transfer receipt for a manual order.
     *
     * Public, like the e-ticket page it lives on: a ticket buyer never has to
     * sign up, so the order's unguessable id is the only credential there is —
     * exactly what `order()` above already relies on.
     */
    public function proof(Request $request, string $order): JsonResponse
    {
        $data = $request->validate([
            'payment_proof_url' => ['required', 'string', 'url', 'max:2048'],
        ]);

        $ticketOrder = TicketOrder::findOrFail($order);

        $this->tickets->submitProof($ticketOrder, $data['payment_proof_url']);

        return ApiResponse::success(
            new TicketOrderResource($ticketOrder->fresh()->load([
                'category',
                'event.organization.bankAccounts',
                'tickets.category',
            ])),
            'Bukti pembayaran terkirim. Menunggu verifikasi penyelenggara.',
        );
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
