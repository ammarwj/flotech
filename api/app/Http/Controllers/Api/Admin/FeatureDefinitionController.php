<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FeatureDefinitionRequest;
use App\Http\Resources\FeatureDefinitionResource;
use App\Models\FeatureDefinition;
use App\Models\PlanFeature;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class FeatureDefinitionController extends Controller
{
    public function index(): JsonResponse
    {
        $defs = FeatureDefinition::orderBy('sort_order')->get();

        return ApiResponse::success(FeatureDefinitionResource::collection($defs));
    }

    public function store(FeatureDefinitionRequest $request): JsonResponse
    {
        $def = FeatureDefinition::create($request->validated());

        return ApiResponse::success(new FeatureDefinitionResource($def), 'Definisi fitur dibuat', 201);
    }

    /**
     * Labels are free to change; the key is not, once a plan carries a value
     * for it. Renaming `online_registration` here does not rename the string
     * `PlanGate` asks for — it only orphans every plan's value, which then
     * stops rendering anywhere and reads as "no plan has this feature".
     */
    public function update(FeatureDefinitionRequest $request, FeatureDefinition $featureDefinition): JsonResponse
    {
        $data = $request->validated();
        $key = $featureDefinition->feature_key;

        if ($data['feature_key'] !== $key && $this->plansUsing($key) > 0) {
            return ApiResponse::error(
                "Feature key \"{$key}\" masih dipakai paket — ganti labelnya saja, jangan key-nya.",
                ['feature_key' => ['Masih dipakai nilai fitur paket.']],
                422,
            );
        }

        $featureDefinition->update($data);

        return ApiResponse::success(new FeatureDefinitionResource($featureDefinition), 'Definisi fitur diperbarui');
    }

    /**
     * Same guard as the rename: the values would survive the definition and
     * become invisible, since `/admin/plans` renders the catalog.
     */
    public function destroy(FeatureDefinition $featureDefinition): JsonResponse
    {
        if ($this->plansUsing($featureDefinition->feature_key) > 0) {
            return ApiResponse::error(
                "Feature key \"{$featureDefinition->feature_key}\" masih dipakai paket — hapus nilainya dari tiap paket dulu.",
                ['feature_key' => ['Masih dipakai nilai fitur paket.']],
                422,
            );
        }

        $featureDefinition->delete();

        return ApiResponse::success(null, 'Definisi fitur dihapus');
    }

    private function plansUsing(string $featureKey): int
    {
        return PlanFeature::where('feature_key', $featureKey)->count();
    }
}
