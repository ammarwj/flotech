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

    public function destroy(Plan $plan): JsonResponse
    {
        $plan->delete();

        return ApiResponse::success(null, 'Paket dihapus');
    }
}
