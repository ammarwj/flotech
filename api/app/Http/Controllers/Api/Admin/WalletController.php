<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\AdjustWalletRequest;
use App\Http\Resources\WalletResource;
use App\Models\Wallet;
use App\Services\WalletService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(protected WalletService $wallet) {}

    /** All organizer balances. `?negative=1` surfaces the ones in the red. */
    public function index(Request $request): JsonResponse
    {
        $wallets = Wallet::with('organization')
            ->when($request->boolean('negative'), fn ($q) => $q->where('balance_available', '<', 0))
            ->orderByDesc('balance_available')
            ->get();

        $items = $wallets->map(fn (Wallet $w) => [
            ...(new WalletResource($w))->toArray($request),
            'organization_name' => $w->organization?->name,
        ]);

        return ApiResponse::success($items);
    }

    /** Escape hatch for corrections the normal flows can't express. */
    public function adjust(AdjustWalletRequest $request, string $wallet): JsonResponse
    {
        $model = Wallet::findOrFail($wallet);

        $this->wallet->adjust(
            $model,
            (float) $request->validated('amount'),
            $request->validated('description'),
            auth('api')->user(),
        );

        return ApiResponse::success(new WalletResource($model->fresh()), 'Saldo disesuaikan');
    }
}
