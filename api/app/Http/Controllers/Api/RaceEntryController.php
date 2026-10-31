<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RaceEntryResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceEntry;
use App\Support\ApiResponse;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The organizer's list of runners.
 *
 * Read-only on purpose. Everything that *changes* an entry already has a door:
 * the runner pays through PublicRaceController, an org admin rules on their
 * proof through PaymentVerificationController, a super admin refunds through
 * RefundService, and the hourly sweep expires the abandoned ones. A write
 * endpoint here would be a second way to move money state, and the two would
 * drift — the same reason MatchResultService owns both result doors.
 */
class RaceEntryController extends Controller
{
    /**
     * The filter values the status pills may send.
     *
     * `awaiting_verification` is NOT a sixth status column value — see
     * HasManualPayment for why that state is derived from the proof columns and
     * must never be slipped into `status`, where it would walk past every guard
     * written as `in_array($status, [...])`. It is a filter *question* the
     * screen already asks (the headline counts it), answered here by the
     * trait's own scope. Keeping it out of this list would print a number with
     * no way to see the rows behind it.
     */
    public const AWAITING = 'awaiting_verification';

    /** @var list<string> */
    public const STATUSES = ['pending', 'paid', 'cancelled', 'expired', 'refunded', self::AWAITING];

    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        $filters = $request->validate([
            // Rejected rather than ignored: a typo'd pill value would silently
            // return the unfiltered list under a selected tab.
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'category_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->filtered($event, $filters)
            ->with(['category', 'documents'])
            ->latest()
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));

        return ApiResponse::success([
            'items' => RaceEntryResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                // How many rows the status pill is actually showing, which is
                // what the pager prints. Deliberately distinct from
                // `summary.total` — see summary() for why they differ.
                'count' => $page->total(),
            ],
            'summary' => $this->summary($event, $filters),
            // The category dropdown's options. From the event's own categories
            // rather than from the rows on screen — a category nobody has
            // entered yet still belongs in the list, and filtering by it is how
            // an organizer finds out it has sold nothing.
            'categories' => $event->raceCategories()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
                ->all(),
        ]);
    }

    /**
     * The cards above the list: the event's standing tally.
     *
     * Counted over the SAME filtered set the page comes out of, but before
     * paginating. These used to be counted on the client from whatever rows had
     * loaded, beside a total that came from the paginator — two numbers printed
     * in one sentence and measured against different populations. Unpaginated
     * that merely looked right; the first page of 25 would have read "120
     * pendaftar · 25 lunas" with nothing on screen saying which to believe. The
     * same reasoning as `effective_payment_method`: one sentence, one answer,
     * and the server is the only side that has it.
     *
     * Status is the one filter deliberately ignored, the same call
     * TicketOrderController::summary() makes — these are what the event has
     * taken, and narrowing them would have the "Dibatalkan" pill print "12
     * pendaftar · 0 lunas" over a list doing exactly what was asked.
     *
     * Revenue is `price`, the organizer's net, NOT `gross_amount`: the gateway
     * fee and the service fee on top of it are the platform's and the
     * processor's, and `WalletService::creditRaceEntry()` credits exactly this
     * column. A card reading higher than the wallet it feeds is a number an
     * organizer has to reconcile by hand.
     *
     * Each `clone`d so an aggregate does not consume the builder. The awaiting
     * count goes through HasManualPayment's own scope rather than being spelled
     * out again: it is four conditions, one of them `verified_at IS NULL`, and a
     * second copy here would drift from the queue screen that acts on it — the
     * whole reason that trait derives the state instead of storing it.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, float|int>
     */
    protected function summary(Event $event, array $filters): array
    {
        $all = $this->filtered($event, [...$filters, 'status' => null]);
        $paid = (clone $all)->where('status', 'paid');

        return [
            'total' => (clone $all)->count(),
            'paid' => (clone $paid)->count(),
            'awaiting_verification' => (clone $all)->awaitingVerification()->count(),
            // Of the paid ones, and that pairing is the point: an unpaid entry
            // has no code to scan, so "N dari M" is only a ratio against the
            // runners who can actually turn up.
            'checked_in' => (clone $paid)->whereNotNull('checked_in_at')->count(),
            'revenue' => (float) (clone $paid)->sum('price'),
        ];
    }

    /**
     * The filtered query, built once and read by both the list and the counts.
     * Two copies of these clauses would drift, and the drift would show up as a
     * headline that disagrees with the rows beneath it.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<RaceEntry>
     */
    protected function filtered(Event $event, array $filters): Builder
    {
        return RaceEntry::query()
            ->where('event_id', $event->id)
            ->when(
                $filters['category_id'] ?? null,
                fn ($q, $id) => $q->where('race_category_id', $id),
            )
            ->when(
                ($filters['status'] ?? null) === self::AWAITING,
                fn ($q) => $q->awaitingVerification(),
                fn ($q) => $q->when(
                    $filters['status'] ?? null,
                    fn ($w, $status) => $w->where('status', $status),
                ),
            )
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(
                // Search::anyColumn rather than `ilike`: the latter is
                // Postgres-only so the branch could not be tested, and it does
                // not escape wildcards — one `%` typed into the box returns
                // every runner.
                fn ($w) => Search::anyColumn($w, ['full_name', 'email', 'phone'], (string) $term)
            ));
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }

    protected function findEvent(Request $request, string $eventId): Event
    {
        return $this->org($request)->events()->findOrFail($eventId);
    }
}
