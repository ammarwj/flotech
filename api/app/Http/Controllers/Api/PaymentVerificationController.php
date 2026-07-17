<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Http\Resources\TicketOrderResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TicketOrder;
use App\Services\PaymentRails;
use App\Services\RegistrationService;
use App\Services\TicketService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manual-transfer payments waiting on a human, per event.
 *
 * Behind `org.admin` rather than plain `tenant`: approving a receipt issues a
 * valid ticket (or admits a paying team) on nothing but someone's say-so, so a
 * gate operator must not be able to do it. Same rule as the wallet endpoints.
 */
class PaymentVerificationController extends Controller
{
    public function __construct(
        protected TicketService $tickets,
        protected RegistrationService $registration,
        protected PaymentRails $rails,
    ) {}

    /**
     * Everything awaiting verification for this event, both rails of income.
     */
    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $eventModel = $this->event($request, $event);

        return ApiResponse::success([
            'tickets' => TicketOrderResource::collection(
                $eventModel->ticketOrders()->awaitingVerification()->with('category')->latest('payment_proof_uploaded_at')->get(),
            ),
            'teams' => TeamResource::collection(
                $eventModel->teams()->awaitingVerification()->with('category')->latest('payment_proof_uploaded_at')->get(),
            ),
        ]);
    }

    public function approveTicket(Request $request, string $organization, string $event, string $order): JsonResponse
    {
        $model = $this->ticketOrder($request, $event, $order);

        $this->tickets->approveProof($model, $request->user());

        return ApiResponse::success(
            new TicketOrderResource($model->fresh()->load('category')),
            'Pembayaran diterima. Tiket sudah berlaku.',
        );
    }

    public function rejectTicket(Request $request, string $organization, string $event, string $order): JsonResponse
    {
        $model = $this->ticketOrder($request, $event, $order);

        $this->tickets->rejectProof($model, $this->reason($request), $this->rails->deadline());

        return ApiResponse::success(
            new TicketOrderResource($model->fresh()->load('category')),
            'Bukti ditolak. Pembeli dapat mengunggah ulang.',
        );
    }

    public function approveTeam(Request $request, string $organization, string $event, string $team): JsonResponse
    {
        $model = $this->team($request, $event, $team);

        $this->registration->approveProof($model, $request->user());

        return ApiResponse::success(
            new TeamResource($model->fresh()->load('category')),
            'Pembayaran diterima.',
        );
    }

    public function rejectTeam(Request $request, string $organization, string $event, string $team): JsonResponse
    {
        $model = $this->team($request, $event, $team);

        $this->registration->rejectProof($model, $this->reason($request), $this->rails->deadline());

        return ApiResponse::success(
            new TeamResource($model->fresh()->load('category')),
            'Bukti ditolak. Tim dapat mengunggah ulang.',
        );
    }

    private function reason(Request $request): string
    {
        return $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];
    }

    private function ticketOrder(Request $request, string $eventId, string $orderId): TicketOrder
    {
        return $this->event($request, $eventId)->ticketOrders()->findOrFail($orderId);
    }

    private function team(Request $request, string $eventId, string $teamId): Team
    {
        return $this->event($request, $eventId)->teams()->findOrFail($teamId);
    }

    /**
     * Resolve an event scoped to the current organization.
     */
    private function event(Request $request, string $eventId): Event
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org->events()->findOrFail($eventId);
    }
}
