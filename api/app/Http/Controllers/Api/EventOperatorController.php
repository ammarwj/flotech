<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\InviteEventOperatorRequest;
use App\Http\Requests\Event\UpdateEventOperatorRequest;
use App\Http\Resources\EventOperatorInviteResource;
use App\Http\Resources\EventOperatorResource;
use App\Models\EventOperator;
use App\Models\EventOperatorInvite;
use App\Models\Organization;
use App\Services\EventOperatorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organizer side of event operator management — who may invite, resend,
 * revoke, edit permissions and remove. All of it `org.admin`: an operator is
 * handed a slice of the organizer surface, and only the owner/admin may
 * decide who gets one, same tier as the wallet and plan-orders.
 */
class EventOperatorController extends Controller
{
    public function __construct(protected EventOperatorService $operators) {}

    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        return ApiResponse::success(
            EventOperatorResource::collection($event->operators()->with(['user', 'invitedBy'])->get()),
        );
    }

    public function invites(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        return ApiResponse::success(
            EventOperatorInviteResource::collection(
                $event->operatorInvites()->with('invitedBy')->latest()->get()
            ),
        );
    }

    public function invite(InviteEventOperatorRequest $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        $invite = $this->operators->invite(
            $event,
            $request->validated()['email'],
            $request->permissions(),
            $request->user(),
        );

        return ApiResponse::success(new EventOperatorInviteResource($invite), 'Undangan dikirim', 201);
    }

    public function resend(Request $request, string $organization, string $invite): JsonResponse
    {
        $inviteModel = $this->findInvite($request, $invite);

        $this->operators->resend($inviteModel);

        return ApiResponse::success(new EventOperatorInviteResource($inviteModel), 'Undangan dikirim ulang');
    }

    public function revoke(Request $request, string $organization, string $invite): JsonResponse
    {
        $inviteModel = $this->findInvite($request, $invite);

        $this->operators->revoke($inviteModel);

        return ApiResponse::success(null, 'Undangan dibatalkan');
    }

    public function updatePermissions(UpdateEventOperatorRequest $request, string $organization, string $operator): JsonResponse
    {
        $operatorModel = $this->findOperator($request, $operator);

        $this->operators->updatePermissions($operatorModel, $request->permissions());

        return ApiResponse::success(new EventOperatorResource($operatorModel->fresh(['user', 'invitedBy'])), 'Akses operator diperbarui');
    }

    public function remove(Request $request, string $organization, string $operator): JsonResponse
    {
        $operatorModel = $this->findOperator($request, $operator);

        $this->operators->remove($operatorModel);

        return ApiResponse::success(null, 'Operator dihapus');
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }

    protected function findEvent(Request $request, string $eventId)
    {
        return $this->org($request)->events()->findOrFail($eventId);
    }

    protected function findInvite(Request $request, string $inviteId): EventOperatorInvite
    {
        return EventOperatorInvite::query()
            ->whereHas('event', fn ($q) => $q->where('organization_id', $this->org($request)->id))
            ->findOrFail($inviteId);
    }

    protected function findOperator(Request $request, string $operatorId): EventOperator
    {
        return EventOperator::query()
            ->whereHas('event', fn ($q) => $q->where('organization_id', $this->org($request)->id))
            ->findOrFail($operatorId);
    }
}
