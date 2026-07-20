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
            'trend' => $this->views->platformTrend(),
        ]);
    }

    public function organizations(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'items' => $this->views->breakdownByOrganization($this->limit($request)),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'items' => $this->views->breakdownByEvent(
                $request->query('organization_id'),
                $this->limit($request),
            ),
        ]);
    }

    private function limit(Request $request): int
    {
        return min(max((int) $request->query('limit', 20), 1), 100);
    }
}
