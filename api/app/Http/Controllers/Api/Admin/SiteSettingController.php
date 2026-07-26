<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSiteSettingsRequest;
use App\Http\Resources\SiteSettingResource;
use App\Models\SiteSetting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Contact details and social profiles for the landing footer. A settings pair
 * (index/update) rather than an apiResource: this is one row, not a list, so it
 * has no is_active/sort_order and nothing to create or delete.
 */
class SiteSettingController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(new SiteSettingResource(SiteSetting::current()));
    }

    public function update(UpdateSiteSettingsRequest $request): JsonResponse
    {
        // firstOrNew() with no attributes: always the same singleton row.
        $settings = SiteSetting::query()->first() ?? new SiteSetting;
        $settings->fill($request->validated());
        $settings->updated_by = auth('api')->id();
        $settings->save();

        return ApiResponse::success(new SiteSettingResource($settings), 'Kontak & sosmed disimpan');
    }
}
