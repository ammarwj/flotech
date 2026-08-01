<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicOrganizationResource;
use App\Models\Organization;
use App\Services\PlanGate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PublicOrganizationController extends Controller
{
    public function __construct(protected PlanGate $gate) {}

    /**
     * Public organizer profile addressed by slug. The organizer's events are
     * fetched separately from the catalog (GET /public/events?org=slug).
     *
     * Without the `organizer_profile` entitlement the page degrades to a bare
     * name and event grid rather than 404ing: `/{orgSlug}` is the parent of
     * every public event URL, and the events listed below it have to keep
     * working whatever the organizer bought.
     */
    public function show(string $orgSlug): JsonResponse
    {
        $org = Organization::query()
            ->where('slug', $orgSlug)
            ->withCount(['events as published_events_count' => fn ($q) => $q->where('status', '!=', 'draft')])
            ->firstOrFail();

        return ApiResponse::success(new PublicOrganizationResource(
            $org,
            $this->gate->orgAllows($org, 'organizer_profile'),
        ));
    }
}
