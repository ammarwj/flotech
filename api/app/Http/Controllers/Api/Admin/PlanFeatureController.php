<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncPlanFeaturesRequest;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PlanFeatureController extends Controller
{
    /**
     * Replace the feature values for a plan (upsert by key, prune removed).
     */
    public function sync(SyncPlanFeaturesRequest $request, Plan $plan): JsonResponse
    {
        /** @var array<string, string|null> $features */
        $features = $request->validated('features');

        foreach ($features as $key => $value) {
            $plan->features()->updateOrCreate(
                ['feature_key' => $key],
                ['value' => (string) ($value ?? '')],
            );
        }

        // Remove features no longer present in the payload.
        $plan->features()
            ->whereNotIn('feature_key', array_keys($features))
            ->delete();

        return ApiResponse::success(
            new PlanResource($plan->load('features')),
            'Fitur paket diperbarui',
        );
    }
}
