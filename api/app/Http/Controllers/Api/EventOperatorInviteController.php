<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventOperatorInviteResource;
use App\Models\EventOperatorInvite;
use App\Services\EventOperatorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public/auth half of the invite flow — everything that happens OUTSIDE
 * `organizations/{organization}`, since the person clicking the link may not
 * be a member of anything yet.
 *
 * `show()` is unauthenticated on purpose: the preview page has to render
 * before anybody has logged in, to decide whether to offer a login or a
 * register CTA. `accept()` and `pending()` are `auth:api` — accepting IS the
 * proof-of-email step, and listing "my pending invites" only makes sense for
 * a signed-in account.
 */
class EventOperatorInviteController extends Controller
{
    public function __construct(protected EventOperatorService $operators) {}

    public function show(Request $request, string $token): JsonResponse
    {
        $invite = EventOperatorInvite::query()
            ->with(['event.organization', 'invitedBy'])
            ->where('token', $token)
            ->first();

        if (! $invite) {
            return ApiResponse::error('Undangan tidak ditemukan.', null, 404);
        }

        return ApiResponse::success([
            'invite' => new EventOperatorInviteResource($invite),
            'event' => [
                'id' => $invite->event->id,
                'name' => $invite->event->name,
                'banner_url' => $invite->event->banner_url,
            ],
            'organization' => [
                'id' => $invite->event->organization->id,
                'name' => $invite->event->organization->name,
            ],
            'usable' => $invite->isUsable(),
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $invite = EventOperatorInvite::query()->where('token', $token)->first();

        if (! $invite) {
            return ApiResponse::error('Undangan tidak ditemukan.', null, 404);
        }

        $operator = $this->operators->accept($invite, $request->user());

        return ApiResponse::success(
            ['event_id' => $operator->event_id],
            'Undangan diterima',
        );
    }

    /**
     * Pending invites addressed to the signed-in account's own email.
     *
     * Exact match on purpose, not case-insensitive. `users.email` is unique
     * per-casing but registration never requires verifying it first, so two
     * rows can disagree only on case (`victim@x.com` vs `VICTIM@x.com`) and
     * belong to two different people. A case-insensitive match here would let
     * the second account read the first's pending invites — event name, org,
     * permission category, expiry. `EventOperatorService::normalizeEmail()`
     * already lowercases every invite's `email` at write time, so a real
     * invite only ever needs to match the lowercase address an organizer
     * typed, and an account signed in with that same casing matches it exactly.
     */
    public function pending(Request $request): JsonResponse
    {
        $invites = EventOperatorInvite::query()
            ->with(['event.organization', 'invitedBy'])
            ->where('status', EventOperatorInvite::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->where('email', $request->user()->email)
            ->latest()
            ->get();

        return ApiResponse::success(EventOperatorInviteResource::collection($invites));
    }

    /**
     * Events this account operates — the landing page for a user who holds
     * no `organization_members` seat at all. See
     * `docs/event-operator-design.md` section 9: the frontend's dashboard
     * mode logic needs this list before it can decide where such an account
     * lands instead of `/onboarding`.
     */
    public function myEvents(Request $request): JsonResponse
    {
        $operators = $request->user()->operatorAssignments()
            ->with(['event.organization'])
            ->get();

        return ApiResponse::success($operators->map(fn ($operator) => [
            'event' => [
                'id' => $operator->event->id,
                'name' => $operator->event->name,
                'banner_url' => $operator->event->banner_url,
                'status' => $operator->event->status,
            ],
            'organization' => [
                'id' => $operator->event->organization->id,
                'name' => $operator->event->organization->name,
            ],
            'can_ticketing' => $operator->can_ticketing,
            'can_scheduling' => $operator->can_scheduling,
            'can_registration' => $operator->can_registration,
            'can_documents' => $operator->can_documents,
        ])->values());
    }
}
