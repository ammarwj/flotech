<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketOrderResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketOrder;
use App\Support\ApiResponse;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketOrderController extends Controller
{
    /**
     * Buyer list for an event: every ticket order with its issued tickets and
     * their check-in state.
     *
     * Behind `org.admin` rather than plain `tenant`, unlike the aggregate
     * ticket report — the rows carry buyer emails and phone numbers, which a
     * gate operator has no business reading.
     *
     * Paginated and filtered server-side. The page used to load every order and
     * filter in the browser, which is fine for a club match and not for a run
     * selling thousands of passes — and the search box could only ever match
     * what the page had already downloaded.
     *
     * The date filter is on `tickets.event_date`, the day a holder attends, not
     * on when the order was placed: the question an organizer asks on a
     * multi-day event is "who is coming on Saturday". That makes it meaningful
     * only for per-day and pass categories — a `none` order has no dates at all
     * (`event_dates` null) and is deliberately excluded while a day is picked,
     * because it makes no claim about any particular day.
     */
    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->event($request, $event);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['paid', 'pending', 'cancelled', 'refunded'])],
            'category_id' => ['nullable', 'uuid'],
            // Both inclusive, on the attendance day, as YYYY-MM-DD.
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->filtered($event, $filters)
            ->with(['category', 'tickets'])
            ->latest('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));

        return ApiResponse::success([
            'items' => TicketOrderResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
            // The three cards above the list. Computed over the whole filtered
            // set, not the page: a per-page sum would call page 2 of a sold-out
            // event "20 pesanan lunas" and the number would change as the
            // organizer paged through it.
            'summary' => $this->summary($event, $filters),
            // The category dropdown's options. From the event's own categories
            // rather than from the rows on screen — a category nobody has
            // bought from yet still belongs in the list, and filtering by it is
            // how an organizer finds out it has sold nothing.
            'categories' => $event->ticketCategories()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
                ->all(),
        ]);
    }

    /**
     * Sales totals over the filtered set.
     *
     * Paid orders only — pending ones still hold quota but no money. Tickets are
     * counted as issued *rows*, not `quantity`: that column is paid units, and
     * for a pass or a per-day category it is not the number of tickets (a
     * three-day buyer is one seat and three QRs). `ticket-report` counts the
     * same rows, so the two surfaces cannot disagree.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, float|int>
     */
    protected function summary(Event $event, array $filters): array
    {
        // Status is the one filter deliberately ignored here: these cards
        // report sales, and they are the only place an organizer looking at the
        // "Dibatalkan" tab can still see what the event actually took.
        $paid = $this->filtered($event, [...$filters, 'status' => 'paid']);

        $orders = (clone $paid)->count();
        $revenue = (float) (clone $paid)->sum('total_price');

        $tickets = $event->tickets()
            ->whereIn('order_id', (clone $paid)->select('ticket_orders.id'))
            ->when(
                $filters['from'] ?? null,
                fn ($q, $from) => $q->whereDate('event_date', '>=', $from),
            )
            ->when(
                $filters['to'] ?? null,
                fn ($q, $to) => $q->whereDate('event_date', '<=', $to),
            )
            ->count();

        return [
            'paid_orders' => $orders,
            'sold_tickets' => $tickets,
            'revenue' => $revenue,
        ];
    }

    /**
     * The filtered order query, built once and read by both the list and the
     * summary cards. Two copies of these five clauses would drift, and the
     * drift would show up as cards that disagree with the rows beneath them.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<TicketOrder>
     */
    protected function filtered(Event $event, array $filters): Builder
    {
        // TicketOrder::query() rather than the relation: this builder is cloned
        // three times (list, order count, revenue) and read as a subquery by the
        // ticket count, and a HasMany is not a Builder.
        return TicketOrder::query()
            ->where('event_id', $event->id)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(
                $filters['category_id'] ?? null,
                fn ($q, $id) => $q->where('ticket_category_id', $id),
            )
            // Through the tickets, not `event_dates`: that column is a JSON
            // array, and a range over it would have to be written per driver
            // (sqlite in tests, Postgres in production) — exactly the split
            // that made `Search` exist. The ticket rows already carry one date
            // each, indexed, and are what the day filter is really about.
            ->when(
                ($filters['from'] ?? null) || ($filters['to'] ?? null),
                fn ($q) => $q->whereHas(
                    'tickets',
                    fn ($t) => $t
                        ->when(
                            $filters['from'] ?? null,
                            fn ($w, $from) => $w->whereDate('event_date', '>=', $from),
                        )
                        ->when(
                            $filters['to'] ?? null,
                            fn ($w, $to) => $w->whereDate('event_date', '<=', $to),
                        ),
                ),
            )
            // Search::anyColumn rather than a plain LIKE: LIKE is
            // case-sensitive on Postgres, so "Budi" would miss budi@test.com.
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(
                fn ($w) => Search::anyColumn(
                    $w,
                    ['buyer_name', 'buyer_email', 'buyer_phone'],
                    (string) $term,
                )->orWhereHas(
                    'tickets',
                    fn ($t) => Search::anyColumn($t, ['holder_name'], (string) $term),
                ),
            ));
    }

    /**
     * Resolve an event scoped to the current organization.
     */
    protected function event(Request $request, string $eventId): Event
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org->events()->findOrFail($eventId);
    }
}
