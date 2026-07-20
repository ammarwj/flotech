<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Services\EventViewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public page traffic as seen by the organizer.
 *
 * Sits behind `tenant` only, not `org.admin` — same call as
 * ScanController@report: these are aggregates with no personal data in them,
 * so an `operator` member reading them gives nothing away.
 */
class EventViewStatController extends Controller
{
    public function __construct(protected EventViewService $views) {}

    /** Traffic for one event: totals plus a 30-day trend. */
    public function event(Organization $organization, Event $event): JsonResponse
    {
        abort_unless($event->organization_id === $organization->id, 404);

        return ApiResponse::success([
            'totals' => $this->views->totalsForEvent($event),
            'trend' => $this->views->trendForEvent($event),
        ]);
    }

    /** Traffic across every event of the organization, plus a per-event table. */
    public function organization(Organization $organization): JsonResponse
    {
        return ApiResponse::success([
            'totals' => $this->views->totalsForOrganization($organization),
            'trend' => $this->views->trendForOrganization($organization),
            'events' => $this->views->eventBreakdownForOrganization($organization),
        ]);
    }
}
