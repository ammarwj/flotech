<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceEntry;
use App\Services\Catalog;
use App\Services\RaceEntryService;
use App\Support\ApiResponse;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Check a runner in at the registration desk, and the log of who came.
 *
 * Its own controller rather than a branch inside ScanController, and the gap
 * between the two is the whole reason. That one is built on `tickets`: it reads
 * `$ticket->order->status`, admits a whole order at once, and compares
 * `event_date` against today. A runner has no order to admit, no second day to
 * be wrong about, and no party standing behind them — so three of its four
 * refusals do not exist here and `whole_order` has nothing to mean. Widening it
 * would put a two-table branch in five methods to answer a question only one
 * rail asks, which is the shape CLAUDE.md already refused for
 * BillingDocumentService.
 *
 * What IS shared is the discipline: the three columns behind a check-in are
 * written in exactly one place (RaceEntryService::checkIn), the log is
 * paginated rather than a fixed tail, and both live at plain `tenant` because
 * the person running the desk is the operator, not the owner.
 */
class RaceScanController extends Controller
{
    public function __construct(protected RaceEntryService $entries) {}

    /**
     * Validate a scanned code and check the runner in.
     *
     * Scoped to the event, so a code from another race cannot open this one.
     * Three refusals, in a fixed order that matters:
     *
     * 1. unknown code — nothing to say about it;
     * 2. not paid — the entry exists but its money never arrived;
     * 3. already checked in — last, deliberately, so a runner who comes back a
     *    second time reads "sudah check-in" rather than anything vaguer.
     *
     * There is no `wrong_day` arm. A race is one morning; `events.start_date`
     * would be the only thing to compare against, and refusing a code because
     * somebody arrived at the pre-race-pack collection the evening before is a
     * rule nobody asked for. If a multi-day race ever needs one, it belongs
     * here as a fourth arm — not folded into the paid check.
     */
    public function checkIn(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        $data = $request->validate([
            'qr_code' => ['required', 'string'],
        ]);

        $entry = $event->raceEntries()
            ->with('category')
            ->where('qr_code', $data['qr_code'])
            ->first();

        if (! $entry) {
            return ApiResponse::error('Peserta tidak ditemukan untuk event ini.', ['result' => 'invalid'], 404);
        }

        if ($entry->status !== 'paid') {
            return ApiResponse::error('Pendaftaran ini belum lunas.', [
                'result' => 'unpaid',
                'entry' => $this->entryPayload($entry),
            ], 409);
        }

        if ($entry->checked_in_at !== null) {
            return ApiResponse::error('Peserta ini sudah check-in.', [
                'result' => 'used',
                'entry' => $this->entryPayload($entry),
            ], 409);
        }

        // Through the service, never inline: those three columns have one
        // writer so a second door cannot disagree about what "checked in"
        // means. Same reasoning as TicketService::checkIn().
        $this->entries->checkIn($entry, auth('api')->id());

        return ApiResponse::success([
            'result' => 'valid',
            'entry' => $this->entryPayload($entry->fresh('category')),
        ], 'Check-in berhasil');
    }

    /**
     * Desk summary: how many are expected, how many have arrived.
     *
     * Paid entries only, every figure. `checkIn()` refuses an unpaid code
     * outright, so an unpaid entry in the denominator is a remainder that can
     * never reach zero — the same trap `ScanController::report()` documents for
     * tickets written at purchase rather than at payment.
     *
     * `taken` is deliberately absent from these numbers: that column counts the
     * quota reservation, which an abandoned checkout legitimately holds, and
     * mixing it in would tell the desk to expect people who never paid.
     */
    public function report(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        $paid = fn () => $event->raceEntries()->where('status', 'paid');

        $total = $paid()->count();
        $arrived = $paid()->whereNotNull('checked_in_at')->count();

        $categories = $event->raceCategories()
            ->withCount([
                'entries as paid_count' => fn ($q) => $q->where('status', 'paid'),
                'entries as checked_in_count' => fn ($q) => $q->where('status', 'paid')
                    ->whereNotNull('checked_in_at'),
            ])
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'quota' => $c->quota,
                'paid' => $c->paid_count,
                'checked_in' => $c->checked_in_count,
            ]);

        return ApiResponse::success([
            'checkin' => [
                'total' => $total,
                'checked_in' => $arrived,
                'remaining' => max(0, $total - $arrived),
            ],
            'categories' => $categories,
        ]);
    }

    /**
     * The check-in log, newest first, paginated.
     *
     * No `date` filter, unlike the ticket log: that one exists because a
     * multi-day pass separates the attendance day from the scan moment, and a
     * race has one morning. Adding it would be a filter over a column that is
     * always the same day.
     */
    public function checkins(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $event->raceEntries()
            ->where('status', 'paid')
            ->whereNotNull('checked_in_at')
            ->when(
                $filters['q'] ?? null,
                // Search::anyColumn, not `ilike`: the latter is Postgres-only
                // so the branch could not be tested, and it does not escape
                // wildcards — one `%` typed at the desk returns every runner.
                fn ($q, $term) => Search::anyColumn($q, ['full_name', 'bib_number'], $term),
            )
            ->with('category')
            ->latest('checked_in_at')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));

        return ApiResponse::success([
            'items' => collect($page->items())->map(fn (RaceEntry $e) => $this->entryPayload($e))->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * What the desk screen needs, and nothing more.
     *
     * Deliberately NOT RaceEntryResource: that one publishes an email, a phone
     * number and an emergency contact, which is why the organizer's list of
     * runners sits behind `org.admin`. This endpoint is `tenant` — the person
     * holding the scanner is the operator — so it carries the name on the bib
     * and the category, the same line ScanController::ticketPayload() draws.
     *
     * @return array<string, mixed>
     */
    protected function entryPayload(RaceEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'full_name' => $entry->full_name,
            'category' => $entry->category?->name,
            // Null until somebody fills it in; the desk shows the name either
            // way rather than waiting on a number that may never come.
            'bib_number' => $entry->bib_number,
            'checked_in_at' => $entry->checked_in_at,
        ];
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }

    /**
     * Resolve the event, and refuse one that holds matches.
     *
     * 404 rather than 403: this endpoint does not exist for a football event,
     * and its codes are `tickets` rows that `ScanController` already owns.
     */
    protected function findEvent(Request $request, string $eventId): Event
    {
        $event = $this->org($request)->events()->findOrFail($eventId);

        abort_if(Catalog::scoringOf($event->sport_type) !== 'none', 404);

        return $event;
    }
}
