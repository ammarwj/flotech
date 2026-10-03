<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateLandingStatsRequest;
use App\Services\LandingStatService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Which counters the landing "Proof" strip shows, what they are called and in
 * what order. A settings pair (index/update) rather than an apiResource: the row
 * set is decided by App\Support\LandingMetrics, so there is nothing here to
 * create or delete — only to override.
 */
class LandingStatController extends Controller
{
    /**
     * Deliberately uncached, unlike the public endpoint. That ten-minute TTL
     * exists to spare visitors a full scan of `event_view_daily`, not to hide an
     * admin's own edit from them for ten minutes.
     */
    public function index(LandingStatService $stats): JsonResponse
    {
        return ApiResponse::success($stats->effectiveWithValues());
    }

    public function update(UpdateLandingStatsRequest $request, LandingStatService $stats): JsonResponse
    {
        $stats->put($request->validated()['metrics'], auth('api')->user());

        return ApiResponse::success($stats->effectiveWithValues(), 'Counter landing disimpan');
    }
}
