<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RaceEntryResource;
use App\Models\Event;
use App\Models\Organization;
use App\Support\ApiResponse;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        $entries = $event->raceEntries()
            ->with(['category', 'documents'])
            ->when($request->query('category_id'), fn ($q, $id) => $q->where('race_category_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('search'), fn ($q, $term) => $q->where(
                // Search::anyColumn rather than `ilike`: the latter is
                // Postgres-only so the branch could not be tested, and it does
                // not escape wildcards — one `%` typed into the box returns
                // every runner.
                fn ($w) => Search::anyColumn($w, ['full_name', 'email', 'phone'], (string) $term)
            ))
            ->latest();

        // Counted before paginating, and from the SAME filtered query the page
        // comes out of.
        //
        // The screen prints "N pendaftar · M lunas" together, and those were
        // being measured against different populations: the total came from the
        // paginator (every match) while the other two were counted on the
        // client from whatever rows had loaded. Unpaginated that merely looked
        // right; the first page of 25 would have read "120 pendaftar · 25
        // lunas" with nothing on screen to say which of the two numbers to
        // believe. The same reasoning as `effective_payment_method` — one
        // sentence, one answer, and the server is the only side that has it.
        //
        // Two counts, each `clone`d so the aggregate does not consume the
        // builder the page still needs. "Awaiting verification" goes through
        // HasManualPayment's own scope rather than being spelled out again:
        // it is four conditions, one of them `verified_at IS NULL`, and a
        // second copy here would drift from the queue screen that acts on it —
        // the whole reason that trait derives the state instead of storing it.
        $paid = (clone $entries)->reorder()->where('status', 'paid')->count();
        $awaiting = (clone $entries)->reorder()->awaitingVerification()->count();

        $entries = $entries->paginate(min((int) $request->query('per_page', 25), 100));

        return ApiResponse::success([
            'items' => RaceEntryResource::collection($entries->items()),
            'meta' => [
                'page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
                // Event-wide (or filter-wide), never page-wide.
                'paid' => $paid,
                'awaiting_verification' => $awaiting,
            ],
        ]);
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
