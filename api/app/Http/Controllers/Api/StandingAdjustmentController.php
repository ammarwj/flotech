<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\StoreStandingAdjustmentRequest;
use App\Http\Resources\StandingAdjustmentResource;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Organization;
use App\Models\StandingAdjustment;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The organizer's manual point adjustments to a category's table — a house rule
 * the fixtures cannot express ("suporter datang lengkap = +2 poin").
 *
 * **Append and remove, never replace.** A full-list PUT — the shape
 * EventPersonnelService::sync() uses — is right there because the payload *is*
 * the form on screen: one person, one list, one sitting. A ledger of incidents
 * is the opposite. Rows accrue across a tournament from several people, each
 * carries its own `created_by`, and no client ever legitimately holds the whole
 * list. The failure mode is concrete: operator A opens the standings tab,
 * operator B adds "+1 Garuda FC", A adds "+2 Elang" and PUTs the list they
 * loaded — B's row is gone, with no error and nothing in the response that
 * differs from a correct save. `wallet_transactions`, the shape this borrows,
 * has no sync endpoint either.
 *
 * **No update action.** A signed number plus a reason plus an author is a
 * statement somebody made; editing it in place would leave the original
 * author's name on a statement they never made ("+2 suporter" quietly becoming
 * "-3 sanksi"). Correcting one means deleting it and typing it again under your
 * own name. The consequence is worth stating plainly: deleting is the only
 * correction, so this is a ledger, not an audit trail.
 *
 * Plain `tenant`, like schedule/draw/knockout/updateResult and unlike anything
 * that moves money: the operator running the table is exactly who the organizer
 * tells "give them the two points for the turnout".
 *
 * No service layer. There is no rule here beyond validation, and StandingService
 * is a producer that must not acquire writers.
 */
class StandingAdjustmentController extends Controller
{
    public function index(Request $request, string $organization, string $event, string $category): JsonResponse
    {
        $category = $this->category($request, $event, $category);

        // Every team of the category, including those whose status is no longer
        // `approved`. StandingService::rows() filters to approved; this must
        // not. An entry typed for a team later disqualified would otherwise be
        // invisible and undeletable while still sitting in the table, ready to
        // reappear the moment the team is approved again.
        $adjustments = $category->adjustments()
            ->with(['team:id,name', 'author:id,full_name'])
            ->latest()
            ->get();

        return ApiResponse::success(StandingAdjustmentResource::collection($adjustments));
    }

    public function store(StoreStandingAdjustmentRequest $request, string $organization, string $event, string $category): JsonResponse
    {
        $category = $this->category($request, $event, $category);
        $data = $request->validated();

        // 422 rather than a silent write: a row filed against a team of another
        // category sums into a table that team does not appear in, so nothing
        // on any screen would ever show it again.
        if (! $category->teams()->whereKey($data['team_id'])->exists()) {
            return ApiResponse::error(
                'Tim tersebut bukan peserta kategori ini.',
                ['team_id' => ['Tim tersebut bukan peserta kategori ini.']],
                422,
            );
        }

        $adjustment = $category->adjustments()->create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        $adjustment->load(['team:id,name', 'author:id,full_name']);

        return ApiResponse::success(
            new StandingAdjustmentResource($adjustment),
            'Penyesuaian poin ditambahkan',
            201,
        );
    }

    public function destroy(Request $request, string $organization, string $adjustment): JsonResponse
    {
        $this->adjustment($request, $adjustment)->delete();

        return ApiResponse::success(null, 'Penyesuaian poin dihapus');
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

    protected function category(Request $request, string $eventId, string $categoryId): EventCategory
    {
        return $this->event($request, $eventId)->categories()->findOrFail($categoryId);
    }

    /** Resolve one entry scoped to an event owned by the current org (404 otherwise). */
    protected function adjustment(Request $request, string $adjustmentId): StandingAdjustment
    {
        return StandingAdjustment::whereHas(
            'category.event',
            fn ($q) => $q->where('organization_id', $this->org($request)->id),
        )->findOrFail($adjustmentId);
    }
}
