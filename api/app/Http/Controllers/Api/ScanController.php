<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Ticket;
use App\Services\TicketService;
use App\Support\ApiResponse;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ScanController extends Controller
{
    public function __construct(protected TicketService $tickets) {}

    /**
     * Validate a scanned QR code and check the holder in (one-time use).
     * Scoped to the event so a ticket from another event can't be used here.
     *
     * `whole_order` admits the rest of the scanned ticket's order in the same
     * call — a group that bought 20 seats arrives as one party holding one
     * phone, and making the gate read 20 codes off it is the slowest possible
     * way to let in people who are already standing together. The flag is the
     * gate's mode, not the buyer's: it is sent per scan so the staff can turn
     * it off for the next lone visitor without reloading anything.
     *
     * The four guards below are untouched by it, and that is the point. They
     * ask about *the scanned ticket*, and a bulk scan must not become a way to
     * skip them: an unpaid, already-used or wrong-day code refuses exactly as
     * it does alone, so one QR can never be presented twice to open an order.
     */
    public function checkIn(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        $data = $request->validate([
            'qr_code' => ['required', 'string'],
            'whole_order' => ['sometimes', 'boolean'],
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

        // Through the service, not inline: the box office checks people in too,
        // and these three columns are written in one place so the two doors
        // cannot disagree about what "used" means.
        //
        // Bulk mode reuses checkInOrder() — the same method the box-office
        // dialog and the buyer list call — rather than looping here. That is
        // what keeps the day rule single-sourced: a three-day pass scanned at
        // the gate on day one must admit day one's seats only, and a third
        // copy of that filter would be a third thing to keep in step.
        // checkInOrder() skips rows already in and returns what it changed, so
        // the scanned ticket is counted exactly once.
        $userId = auth('api')->id();
        $whole = (bool) ($data['whole_order'] ?? false);

        if ($whole && $ticket->order) {
            $checkedIn = $this->tickets->checkInOrder($ticket->order, $userId);
        } else {
            $this->tickets->checkIn($ticket, $userId);
            $checkedIn = 1;
        }

        return ApiResponse::success([
            'result' => 'valid',
            'ticket' => $this->ticketPayload($ticket->fresh('category')),
            // Always sent, 1 on a single scan: a client reading it does not
            // have to know which mode produced the answer, and the gate's
            // screen says "20 tiket" because the server counted 20, not
            // because the client multiplied something.
            'checked_in' => $checkedIn,
            // The order this ticket belongs to, counted today: how many of its
            // seats are now in and how many there are. The gate's next question
            // after "20 masuk" is "out of how many?", and a party arriving in
            // two groups needs the second scan to read 20 of 20, not 5 of 20.
            'order' => $this->orderTodayCount($ticket),
        ], 'Check-in berhasil');
    }

    /**
     * Finance + check-in summary for an event's ticketing.
     */
    public function report(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        $paidOrders = $event->ticketOrders()->where('status', 'paid');

        // Ticket rows are written at purchase, not at payment (TicketService
        // issues the QRs inside the same transaction that creates the pending
        // order), so an unfiltered count reports every abandoned and expired
        // checkout as a sale. Scoped to paid orders here and in every figure
        // derived from it: checkIn() refuses an unpaid QR outright, so an
        // unpaid ticket in the check-in denominator is a remainder that can
        // never reach zero.
        $paidTickets = fn () => $event->tickets()
            ->whereHas('order', fn ($q) => $q->where('status', 'paid'));

        $totalTickets = $paidTickets()->count();
        $checkedIn = $paidTickets()->where('is_used', true)->count();

        $categories = $event->ticketCategories()
            ->withCount([
                // Same paid-only scope as the cards above. `sold` is left
                // alone on purpose: that column is the quota reservation, and
                // a pending checkout really is holding a seat.
                'tickets as issued_count' => fn ($q) => $q->whereHas('order', fn ($o) => $o->where('status', 'paid')),
                'tickets as checked_in_count' => fn ($q) => $q->where('is_used', true)
                    ->whereHas('order', fn ($o) => $o->where('status', 'paid')),
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
        $byDate = $paidTickets()
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
        ]);
    }

    /**
     * The check-in log: every admitted ticket, newest first.
     *
     * Split out of `report()` and paginated. It used to ride along as a fixed
     * 20-row `recent_checkins` tail, which is the one figure on that payload
     * that grows without bound — a gate admitting two thousand people has a
     * log, not a "recent" list, and twenty rows of it answer nothing while
     * costing the summary cards a join on every poll.
     *
     * Paid orders only, the same scope as every figure in `report()`:
     * `checkIn()` refuses an unpaid QR outright, so a row here that the cards
     * do not count could only come from data written behind them.
     *
     * Plain `tenant` like `report()`, not `org.admin`: `holder_name` is the
     * name printed on the pass the operator just scanned, not the buyer's
     * contact details that put `ticket-orders` behind the stricter gate.
     */
    public function checkins(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $event->tickets()
            ->where('is_used', true)
            ->whereHas('order', fn ($q) => $q->where('status', 'paid'))
            ->when(
                $filters['q'] ?? null,
                fn ($q, $term) => Search::anyColumn($q, ['holder_name'], $term),
            )
            // The attendance day, not the moment of the scan: on a multi-day
            // event the question is "who came in on Saturday", and a pass
            // scanned at 00:30 is still Saturday's.
            ->when(
                $filters['date'] ?? null,
                fn ($q, $date) => $q->whereDate('event_date', $date),
            )
            ->with('category')
            ->latest('used_at')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));

        return ApiResponse::success([
            'items' => collect($page->items())->map(fn (Ticket $t) => [
                'id' => $t->id,
                'holder_name' => $t->holder_name,
                'category' => $t->category?->name,
                'event_date' => $t->event_date?->toDateString(),
                'used_at' => $t->used_at,
            ])->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * How many of this order's tickets are already in, out of the ones valid
     * today — the "20 dari 20" the gate reads back.
     *
     * Asked after the writes, so it counts them. Both numbers come from
     * TicketService::ticketsValidToday(), the same method checkInOrder() uses
     * to pick what to admit, so the denominator cannot disagree with what the
     * bulk call did.
     *
     * @return array{checked_in: int, total: int}
     */
    protected function orderTodayCount(Ticket $ticket): array
    {
        if (! $ticket->order) {
            return ['checked_in' => 1, 'total' => 1];
        }

        // One row, two figures: a cloned relation shares its underlying builder,
        // so the `is_used` filter on the first count would leak into the second
        // and the denominator would silently become the numerator.
        $row = $this->tickets->ticketsValidToday($ticket->order)
            ->selectRaw('count(*) as total, sum(case when is_used then 1 else 0 end) as used')
            ->first();

        return [
            'checked_in' => (int) ($row->used ?? 0),
            'total' => (int) ($row->total ?? 0),
        ];
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
