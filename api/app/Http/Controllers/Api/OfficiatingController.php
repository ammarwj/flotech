<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventCategoryResource;
use App\Http\Resources\EventResource;
use App\Http\Resources\MatchResource;
use App\Models\Event;
use App\Models\EventPersonnel;
use App\Services\DisciplineService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a referee or match staff sees of the event they were assigned.
 *
 * Every route here sits behind `event.personnel`, which is the *only* thing
 * that proves the caller belongs to this event — there is no `organization`
 * attribute on these requests and there must never be one (see
 * EventPersonnelScope). That has a concrete consequence for this file: it may
 * not reach for the organizer controllers' helpers, because all of them start
 * from the tenant. Everything it needs comes off the request attributes the
 * middleware set.
 *
 * Deliberately read-only in this phase. The surfaces that write — scores and
 * stats for staff, lineup approvals for referees — are their own controllers
 * behind their own role middleware, so that "can see the schedule" never
 * silently grows into "can change the result".
 */
class OfficiatingController extends Controller
{
    public function __construct(protected DisciplineService $discipline) {}

    /**
     * The events this account is crew on. The landing page needs it before any
     * event id is known, so it is the one route here outside the event prefix —
     * and therefore the one that authorizes itself, by asking only for rows
     * that name this user.
     */
    public function index(Request $request): JsonResponse
    {
        $rows = EventPersonnel::query()
            ->where('user_id', $request->user()->id)
            ->with('event')
            ->get()
            ->filter(fn (EventPersonnel $p) => $p->event !== null)
            ->values();

        return ApiResponse::success($rows->map(fn (EventPersonnel $p) => [
            'personnel_id' => $p->id,
            'event_id' => $p->event_id,
            'event_name' => $p->event->name,
            'event_status' => $p->event->status,
            'start_date' => $p->event->start_date?->toDateString(),
            'end_date' => $p->event->end_date?->toDateString(),
            'location_name' => $p->event->location_name,
            'kind' => $p->kind,
            'role_display' => $p->roleLabel(),
        ])->all());
    }

    /**
     * The event itself, its categories, and which hat this caller wears in it.
     *
     * `assignment` rides along rather than being fetched separately because the
     * whole shell branches on it — a staff page and a referee page are different
     * screens, and the client should not have to guess from which requests
     * happen to 403.
     */
    public function show(Request $request): JsonResponse
    {
        $event = $this->event($request);
        $person = $this->personnel($request);

        $event->load('categories');

        return ApiResponse::success([
            'event' => new EventResource($event),
            'categories' => EventCategoryResource::collection($event->categories),
            'assignment' => [
                'personnel_id' => $person->id,
                'kind' => $person->kind,
                'role_display' => $person->roleLabel(),
            ],
        ]);
    }

    /**
     * The fixtures of one category, ordered exactly as the organizer's schedule
     * orders them — the crew and the committee have to be looking at the same
     * list in the same order, or "laga ketiga" means two different matches.
     */
    public function matches(Request $request, string $event, string $category): JsonResponse
    {
        $eventModel = $this->event($request);

        // Scoped through the event, not looked up by id: a category id from
        // another event would otherwise be readable by anyone on any crew.
        $categoryModel = $eventModel->categories()->findOrFail($category);

        $matches = $categoryModel->matches()
            ->with($categoryModel->usesRubbers()
                ? ['homeTeam.players', 'awayTeam.players', 'rubbers']
                : ['homeTeam', 'awayTeam'])
            ->orderByRaw("coalesce(stage, '') asc")
            ->orderBy('round')
            ->orderBy('order')
            ->get();

        return ApiResponse::success(MatchResource::collection($matches));
    }

    /**
     * Card tallies and who they keep off the pitch, for a whole category.
     *
     * The identical payload MatchController::discipline() and
     * PublicEventController::discipline() return, from the same service — a
     * third reader that computed its own tally would be a third chance for the
     * warning on a fixture card to disagree with the table underneath it.
     */
    public function discipline(Request $request, string $event, string $category): JsonResponse
    {
        $categoryModel = $this->event($request)->categories()->findOrFail($category);

        return ApiResponse::success($this->discipline->forCategory($categoryModel));
    }

    protected function event(Request $request): Event
    {
        /** @var Event $event */
        $event = $request->attributes->get('event');

        return $event;
    }

    protected function personnel(Request $request): EventPersonnel
    {
        /** @var EventPersonnel $person */
        $person = $request->attributes->get('personnel');

        return $person;
    }
}
