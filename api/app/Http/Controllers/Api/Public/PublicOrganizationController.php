<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicOrganizationResource;
use App\Models\Organization;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PublicOrganizationController extends Controller
{
    /**
     * Public organizer profile addressed by slug. The organizer's events are
     * fetched separately from the catalog (GET /public/events?org=slug).
     */
    public function show(string $orgSlug): JsonResponse
    {
        $org = Organization::query()
            ->where('slug', $orgSlug)
            ->withCount(['events as published_events_count' => fn ($q) => $q->where('status', '!=', 'draft')])
            ->firstOrFail();

        return ApiResponse::success(new PublicOrganizationResource($org));
    }
}
