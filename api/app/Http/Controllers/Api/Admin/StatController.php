<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** Platform-wide content counters, for super admins. */
class StatController extends Controller
{
    /**
     * Everything the /admin overview needs in one call.
     *
     * Grouped under `events` so the next batch of metrics (organizations,
     * users, tickets) can join this endpoint without reshaping what the
     * dashboard already reads.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'events' => $this->eventCounts(),
        ]);
    }

    /**
     * @return array{total: int, active: int, ongoing: int, by_status: array<string, int>}
     */
    private function eventCounts(): array
    {
        $counts = Event::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Every known status is present even at zero: a missing key would read
        // as "no such status" instead of "none yet". The list comes from
        // Event::TRANSITIONS so a status added there can't quietly vanish from
        // the breakdown.
        $byStatus = collect(array_keys(Event::TRANSITIONS))
            ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)]);

        // Summed from the raw rows, not from $byStatus: an unknown status left
        // behind by an older release is still a tournament that exists in the
        // system — it just has no row in the breakdown.
        $total = (int) $counts->sum();

        return [
            'total' => $total,
            // Same definition the plan quota uses (EventController@store,
            // isActiveEvent() in web/lib/plan.ts): active = not finished and
            // not cancelled, so drafts count. A third definition here would let
            // /admin and the organizer's quota badge contradict each other.
            // Derived from the same map rather than a second query, so the card
            // and the breakdown under it can never disagree.
            'active' => $total - $byStatus['finished'] - $byStatus['cancelled'],
            'ongoing' => $byStatus['ongoing'],
            'by_status' => $byStatus->all(),
        ];
    }
}
