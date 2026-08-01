<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::with('features')->orderBy('sort_order')->get();

        return ApiResponse::success(PlanResource::collection($plans));
    }

    public function store(PlanRequest $request): JsonResponse
    {
        $plan = Plan::create($request->validated());

        return ApiResponse::success(new PlanResource($plan->load('features')), 'Paket dibuat', 201);
    }

    public function show(Plan $plan): JsonResponse
    {
        return ApiResponse::success(new PlanResource($plan->load('features')));
    }

    public function update(PlanRequest $request, Plan $plan): JsonResponse
    {
        $plan->update($request->validated());

        return ApiResponse::success(new PlanResource($plan->load('features')), 'Paket diperbarui');
    }

    /**
     * Deleting a plan is refused while events still run on it.
     *
     * `events.plan_id` is nullOnDelete, so without this the delete would succeed
     * and silently strip the entitlements off live tournaments — no error
     * anywhere, and the organizer simply finds their certificates gone. Retire a
     * plan with `is_active: false` instead; the row has to stay anyway so old
     * invoices keep naming a real plan.
     */
    public function destroy(Plan $plan): JsonResponse
    {
        if ($plan->events()->exists()) {
            return ApiResponse::error(
                'Paket ini masih dipakai event yang berjalan. Nonaktifkan saja lewat "is_active".',
                null,
                422,
            );
        }

        $plan->delete();

        return ApiResponse::success(null, 'Paket dihapus');
    }
}
