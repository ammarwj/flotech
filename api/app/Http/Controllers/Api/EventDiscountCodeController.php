<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\EventDiscountCodeRequest;
use App\Http\Resources\EventDiscountCodeResource;
use App\Models\Event;
use App\Models\EventDiscountCode;
use App\Models\Organization;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The organizer's own discount codes for one event.
 *
 * Shaped on RaceCategoryController (resolve the event through the org, then the
 * row through the event) with the minimal CRUD body of
 * Admin\DiscountCodeController.
 *
 * **Plain `tenant`, not `org.admin`**, and that is the same call ticket and race
 * categories make: the number typed here is a price list, not a payment. What
 * sits behind `org.admin` is ruling on a transfer receipt.
 *
 * Like Admin\DiscountCodeController there is no "still in use" guard on rename
 * or delete: every registration snapshots the code it used
 * (`teams.discount_*`, `race_entries.discount_*`), so nothing reads a code row
 * back after the fact and an issued invoice stays intact.
 */
class EventDiscountCodeController extends Controller
{
    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        // Unfiltered: an inactive code has to stay visible so the organizer can
        // switch it back on. Same split as /plans and /admin/discount-codes.
        $codes = $event->discountCodes()->latest()->get();

        return ApiResponse::success(EventDiscountCodeResource::collection($codes));
    }

    public function store(EventDiscountCodeRequest $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        $code = $event->discountCodes()->create($request->validated());

        return ApiResponse::success(new EventDiscountCodeResource($code), 'Kode diskon dibuat', 201);
    }

    public function update(EventDiscountCodeRequest $request, string $organization, string $eventDiscountCode): JsonResponse
    {
        $code = $this->findCode($this->org($request), $eventDiscountCode);

        $code->update($request->validated());

        return ApiResponse::success(new EventDiscountCodeResource($code->fresh()), 'Kode diskon diperbarui');
    }

    public function destroy(Request $request, string $organization, string $eventDiscountCode): JsonResponse
    {
        $code = $this->findCode($this->org($request), $eventDiscountCode);

        $code->delete();

        return ApiResponse::success(null, 'Kode diskon dihapus');
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

    /** Resolve a code whose event belongs to the current org (404 otherwise). */
    protected function findCode(Organization $org, string $codeId): EventDiscountCode
    {
        return EventDiscountCode::whereHas('event', fn ($q) => $q->where('organization_id', $org->id))
            ->findOrFail($codeId);
    }
}
