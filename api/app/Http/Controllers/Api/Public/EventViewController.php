<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\EventViewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Page-view beacon for the public event page.
 *
 * Deliberately a separate POST rather than a counter inside
 * PublicEventController@show: that page is a client component fetched with
 * useQuery, so every window refocus, tab remount and back-navigation refetches
 * it and would have counted as another visit. A beacon fires once per tab
 * session, keeps GET free of side effects, and is invisible to crawlers, which
 * fetch HTML but do not run JavaScript.
 */
class EventViewController extends Controller
{
    use Concerns\ResolvesPublicEvent;

    public function __construct(protected EventViewService $views) {}

    public function store(Request $request, string $orgSlug, string $eventSlug): JsonResponse
    {
        $event = $this->resolve($orgSlug, $eventSlug);

        $this->views->record($event, $request->ip(), $request->userAgent());

        // Always 202, whether the hit was counted, deduplicated or dropped as a
        // bot. The caller has nothing to do with the answer, and saying which
        // would tell a scraper how to make its hits count.
        return ApiResponse::success(null, 'OK', 202);
    }
}
