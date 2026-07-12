<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Services\PlatformSettings;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payout policy the platform owner can change without a deploy. Values fall
 * back to config/wallet.php when never set.
 */
class PlatformSettingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(PlatformSettings::all());
    }

    public function update(UpdatePlatformSettingsRequest $request): JsonResponse
    {
        PlatformSettings::put($request->validated(), auth('api')->user());

        return ApiResponse::success(PlatformSettings::all(), 'Pengaturan disimpan');
    }
}
