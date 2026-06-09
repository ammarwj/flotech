<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Ticket;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ScanController extends Controller
{
    /**
     * Validate a scanned QR code and check the holder in (one-time use).
     * Scoped to the event so a ticket from another event can't be used here.
     */
    public function checkIn(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        $data = $request->validate([
            'qr_code' => ['required', 'string'],
        ]);

        $ticket = $event->tickets()
            ->with(['category', 'order'])
            ->where('qr_code', $data['qr_code'])
            ->first();

        if (! $ticket) {
            return ApiResponse::error('Tiket tidak ditemukan untuk event ini.', ['result' => 'invalid'], 404);
        }

        if ($ticket->order?->status !== 'paid') {
            return ApiResponse::error('Tiket ini belum dibayar.', [
                'result' => 'unpaid',
                'ticket' => $this->ticketPayload($ticket),
            ], 409);
        }

        if ($ticket->is_used) {
            return ApiResponse::error('Tiket ini sudah digunakan.', [
                'result' => 'used',
                'ticket' => $this->ticketPayload($ticket),
            ], 409);
        }

        $ticket->update([
            'is_used' => true,
            'used_at' => Carbon::now(),
            'used_by' => auth('api')->id(),
        ]);

        return ApiResponse::success([
            'result' => 'valid',
            'ticket' => $this->ticketPayload($ticket->fresh('category')),
        ], 'Check-in berhasil');
    }

    /**
     * Finance + check-in summary for an event's ticketing.
     */
    public function report(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        $paidOrders = $event->ticketOrders()->where('status', 'paid');
        $totalTickets = $event->tickets()->count();
        $checkedIn = $event->tickets()->where('is_used', true)->count();

        $categories = $event->ticketCategories()
            ->withCount([
                'tickets as issued_count',
                'tickets as checked_in_count' => fn ($q) => $q->where('is_used', true),
            ])
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'price' => (float) $c->price,
                'quota' => $c->quota,
                'sold' => $c->sold,
                'issued' => $c->issued_count,
                'checked_in' => $c->checked_in_count,
            ]);

        $recent = $event->tickets()
            ->where('is_used', true)
            ->with('category')
            ->latest('used_at')
            ->limit(20)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'holder_name' => $t->holder_name,
                'category' => $t->category?->name,
                'used_at' => $t->used_at,
            ]);

        return ApiResponse::success([
            'finance' => [
                'gross_revenue' => (float) $paidOrders->sum('total_price'),
                'platform_fee' => (float) (clone $paidOrders)->sum('platform_fee'),
                'paid_orders' => (clone $paidOrders)->count(),
                'tickets_sold' => $totalTickets,
            ],
            'checkin' => [
                'total' => $totalTickets,
                'checked_in' => $checkedIn,
                'remaining' => max(0, $totalTickets - $checkedIn),
            ],
            'categories' => $categories,
            'recent_checkins' => $recent,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function ticketPayload(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'holder_name' => $ticket->holder_name,
            'category' => $ticket->category?->name,
            'used_at' => $ticket->used_at,
        ];
    }

    protected function findEvent(Request $request, string $eventId): Event
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org->events()->findOrFail($eventId);
    }
}
