<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FeatureDefinitionRequest;
use App\Http\Resources\FeatureDefinitionResource;
use App\Models\FeatureDefinition;
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

    public function update(FeatureDefinitionRequest $request, FeatureDefinition $featureDefinition): JsonResponse
    {
        $featureDefinition->update($request->validated());

        return ApiResponse::success(new FeatureDefinitionResource($featureDefinition), 'Definisi fitur diperbarui');
    }

    public function destroy(FeatureDefinition $featureDefinition): JsonResponse
    {
        $featureDefinition->delete();

        return ApiResponse::success(null, 'Definisi fitur dihapus');
    }
}
