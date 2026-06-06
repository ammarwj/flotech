<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\Event;
use App\Models\Organization;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class RegistrationController extends Controller
{
    /**
     * List all team registrations for an event (organizer view).
     */
    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $teams = $this->event($request, $event)
            ->teams()
            ->with(['players', 'documents'])
            ->latest('registered_at')
            ->get();

        return ApiResponse::success(TeamResource::collection($teams));
    }

    /**
     * Approve / reject / change a registration's status.
     */
    public function updateStatus(Request $request, string $organization, string $event, string $team): JsonResponse
    {
        $eventModel = $this->event($request, $event);
        $teamModel = $eventModel->teams()->findOrFail($team);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected', 'disqualified', 'withdrawn'])],
            'group_name' => ['nullable', 'string', 'max:10'],
        ]);

        $teamModel->update([
            'status' => $validated['status'],
            'group_name' => $validated['group_name'] ?? $teamModel->group_name,
            'approved_at' => $validated['status'] === 'approved' ? Carbon::now() : null,
        ]);

        return ApiResponse::success(new TeamResource($teamModel->load(['players', 'documents'])), 'Status pendaftaran diperbarui');
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
