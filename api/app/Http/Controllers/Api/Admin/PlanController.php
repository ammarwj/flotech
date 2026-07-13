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
        $plan = Plan::create($this->withYearlyPrice($request->validated()));

        return ApiResponse::success(new PlanResource($plan->load('features')), 'Paket dibuat', 201);
    }

    public function show(Plan $plan): JsonResponse
    {
        return ApiResponse::success(new PlanResource($plan->load('features')));
    }

    public function update(PlanRequest $request, Plan $plan): JsonResponse
    {
        $plan->update($this->withYearlyPrice($request->validated(), $plan));

        return ApiResponse::success(new PlanResource($plan->load('features')), 'Paket diperbarui');
    }

    /**
     * The yearly price is never accepted from the client — it is recomputed here
     * from the monthly price and the discount, so the amount SubscriptionService
     * charges always matches the discount the pricing page advertises.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withYearlyPrice(array $data, ?Plan $plan = null): array
    {
        $monthly = (float) ($data['price_monthly'] ?? $plan?->price_monthly ?? 0);
        $discount = (float) ($data['yearly_discount_percent'] ?? $plan?->yearly_discount_percent ?? 0);

        $data['yearly_discount_percent'] = $discount;
        $data['price_yearly'] = Plan::computeYearlyPrice($monthly, $discount);

        return $data;
    }

    public function destroy(Plan $plan): JsonResponse
    {
        $plan->delete();

        return ApiResponse::success(null, 'Paket dihapus');
    }
}
