<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Models\Organization;
use App\Services\PlatformSettings;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform policy the owner can change without a deploy: payout rules and the
 * payment-gateway switch. Values fall back to config/wallet.php and
 * config/payments.php when never set.
 */
class PlatformSettingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->payload());
    }

    public function update(UpdatePlatformSettingsRequest $request): JsonResponse
    {
        PlatformSettings::put($request->validated(), auth('api')->user());

        return ApiResponse::success($this->payload(), 'Pengaturan disimpan');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'settings' => PlatformSettings::all(),
            // Switching the gateway off sends every organization to manual
            // transfer, and one without a payout account simply cannot be paid.
            // The admin should see that number before flipping it, not after
            // the support tickets arrive.
            'orgs_without_bank_account' => Organization::whereDoesntHave(
                'bankAccounts',
                fn ($query) => $query->where('is_primary', true),
            )->count(),
        ];
    }
}
