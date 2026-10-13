<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DiscountCodeRequest;
use App\Http\Resources\DiscountCodeResource;
use App\Models\DiscountCode;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Super admin CRUD for plan checkout discount codes.
 *
 * Unlike FeatureDefinitionController there is no "still in use" guard on rename
 * or delete, and that is deliberate: a feature key is read back by gating, so
 * renaming one orphans every plan's value, whereas an order snapshots the code
 * it used (`event_plan_orders.discount_*`). Nothing reads a code row back after
 * checkout, so editing or deleting one leaves every issued invoice intact —
 * only future checkouts are affected.
 */
class DiscountCodeController extends Controller
{
    public function index(): JsonResponse
    {
        // Unfiltered: an inactive code has to stay visible so the admin can
        // switch it back on. Same split as /plans and /testimonials.
        $codes = DiscountCode::with('plans')->latest()->get();

        return ApiResponse::success(DiscountCodeResource::collection($codes));
    }

    public function store(DiscountCodeRequest $request): JsonResponse
    {
        $code = DiscountCode::create($request->payload());
        $code->plans()->sync($request->planIds());

        return ApiResponse::success(new DiscountCodeResource($code->load('plans')), 'Kode diskon dibuat', 201);
    }

    public function update(DiscountCodeRequest $request, DiscountCode $discountCode): JsonResponse
    {
        $discountCode->update($request->payload());
        $discountCode->plans()->sync($request->planIds());

        return ApiResponse::success(new DiscountCodeResource($discountCode->load('plans')), 'Kode diskon diperbarui');
    }

    public function destroy(DiscountCode $discountCode): JsonResponse
    {
        $discountCode->delete();

        return ApiResponse::success(null, 'Kode diskon dihapus');
    }
}
