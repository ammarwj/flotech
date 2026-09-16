<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\SyncEventPersonnelRequest;
use App\Http\Resources\EventPersonnelResource;
use App\Models\Event;
use App\Models\Organization;
use App\Services\EventPersonnelService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * An event's referees and match staff.
 *
 * Deliberately ungated. This is ordinary event data — the same class of thing
 * as a team's bench, which nothing gates either — and the gate belongs on what
 * consumes it, not on typing a name in. An organizer who writes their referee
 * list down and only later buys the plan that prints cards should not find the
 * list refused in the meantime.
 *
 * Routes live under organizations/{organization}, so every action declares the
 * path params positionally.
 */
class EventPersonnelController extends Controller
{
    public function __construct(protected EventPersonnelService $personnel) {}

    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        return ApiResponse::success(
            EventPersonnelResource::collection($this->event($request, $event)->personnel),
        );
    }

    /**
     * Replace the whole list: a row with an `id` is an update, one without is
     * new, and anything left out is deleted.
     */
    public function sync(SyncEventPersonnelRequest $request, string $organization, string $event): JsonResponse
    {
        $eventModel = $this->event($request, $event);

        DB::transaction(fn () => $this->personnel->sync($eventModel, $request->validated()['personnel']));

        return ApiResponse::success(
            EventPersonnelResource::collection($eventModel->personnel()->get()),
            'Petugas disimpan',
        );
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }

    protected function event(Request $request, string $eventId): Event
    {
        return $this->org($request)->events()->findOrFail($eventId);
    }
}
