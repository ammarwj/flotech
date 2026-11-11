<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\EventViewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Platform-wide public page traffic, for super admins. */
class ViewStatController extends Controller
{
    public function __construct(protected EventViewService $views) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'totals' => $this->views->platformTotals(),
            // Published alongside the all-time pair rather than derived by the
            // client from the trend's last point: that would be a second reader
            // of "which day is today" in a codebase where UTC and WIB disagree
            // for seven hours every morning, and it would silently change
            // meaning if the trend window ever moved.
            'today' => $this->views->platformTotalsToday(),
            'trend' => $this->views->platformTrend(),
        ]);
    }

    /**
     * Both breakdowns answer `{items, has_more}`, which is what lets the page
     * offer "tampilkan lebih banyak" instead of truncating in silence. The
     * search term is handled in the service, not here: these lists are capped,
     * so filtering client-side would only ever search the busiest rows.
     */
    public function organizations(Request $request): JsonResponse
    {
        return ApiResponse::success($this->views->breakdownByOrganization(
            $this->limit($request),
            $this->search($request),
        ));
    }

    public function events(Request $request): JsonResponse
    {
        return ApiResponse::success($this->views->breakdownByEvent(
            $request->query('organization_id'),
            $this->limit($request),
            $this->search($request),
        ));
    }

    /**
     * Ceiling raised to 500: the old 100 was the same kind of silent cut as the
     * default 20, just further down. One row per organization (or per event
     * with any traffic at all) stays a small payload at this size.
     */
    private function limit(Request $request): int
    {
        return min(max((int) $request->query('limit', 20), 1), 500);
    }

    private function search(Request $request): ?string
    {
        $term = trim((string) $request->query('q', ''));

        return $term === '' ? null : $term;
    }
}
