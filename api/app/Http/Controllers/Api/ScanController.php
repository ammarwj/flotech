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

        // Last of the three guards on purpose: a ticket already used on its own
        // day must still read "sudah digunakan", not "salah hari".
        //
        // A null `event_date` skips this entirely — that is what a category not
        // selling by the day issues, and what every ticket predating this
        // feature carries. No second branch keeps those working.
        //
        // "Today" is today in the *event's* zone. Carbon::now() would be UTC
        // (the app timezone), where a WIB morning is still yesterday — so every
        // ticket scanned before 07:00 would be refused, and only then. A bug
        // that depends on the hour passes every test that never names one.
        if ($ticket->event_date) {
            $today = Carbon::now($event->timezone)->toDateString();

            if ($ticket->event_date->toDateString() !== $today) {
                return ApiResponse::error(
                    'Tiket ini untuk tanggal '.$ticket->event_date->translatedFormat('j F Y').', bukan hari ini.',
                    [
                        'result' => 'wrong_day',
                        'ticket' => $this->ticketPayload($ticket),
                    ],
                    409,
                );
            }
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

        // Per-day breakdown. `checkin.total` counts ticket-days now, and an
        // organizer reading it without this split would read it as a head
        // count: a three-day buyer is one person and three rows. Only days
        // that actually have tickets appear, so a `none`-only event gets an
        // empty list and the client shows nothing extra.
        $byDate = $event->tickets()
            ->whereNotNull('event_date')
            ->selectRaw('event_date, count(*) as issued, sum(case when is_used then 1 else 0 end) as checked_in')
            ->groupBy('event_date')
            ->orderBy('event_date')
            ->get()
            ->map(fn ($row) => [
                'event_date' => Carbon::parse($row->event_date)->toDateString(),
                'issued' => (int) $row->issued,
                'checked_in' => (int) $row->checked_in,
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
                // The organizer keeps all of it. `platform_fee` is retired —
                // always 0 on new orders — and the buyer-paid gateway/service
                // fees sit on top of the price, never inside it, so there is
                // nothing left to deduct and nothing "gross" about this number.
                'revenue' => (float) $paidOrders->sum('total_price'),
                'paid_orders' => (clone $paidOrders)->count(),
                'tickets_sold' => $totalTickets,
            ],
            'checkin' => [
                'total' => $totalTickets,
                'checked_in' => $checkedIn,
                'remaining' => max(0, $totalTickets - $checkedIn),
            ],
            'categories' => $categories,
            'by_date' => $byDate,
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
            // Null for a ticket with no day. The scanner renders it on every
            // result, not just the refusals: the gate staff's next question
            // after "salah hari" is "then which day?".
            'event_date' => $ticket->event_date?->toDateString(),
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
