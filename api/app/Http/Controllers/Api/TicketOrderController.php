<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketOrderResource;
use App\Models\Event;
use App\Models\Organization;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketOrderController extends Controller
{
    /**
     * Buyer list for an event: every ticket order with its issued tickets and
     * their check-in state.
     *
     * Behind `org.admin` rather than plain `tenant`, unlike the aggregate
     * ticket report — the rows carry buyer emails and phone numbers, which a
     * gate operator has no business reading.
     */
    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $orders = $this->event($request, $event)
            ->ticketOrders()
            ->with(['category', 'tickets'])
            ->latest('created_at')
            ->get();

        return ApiResponse::success(TicketOrderResource::collection($orders));
    }

    /**
     * Resolve an event scoped to the current organization.
     */
    protected function event(Request $request, string $eventId): Event
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org->events()->findOrFail($eventId);
    }
}
