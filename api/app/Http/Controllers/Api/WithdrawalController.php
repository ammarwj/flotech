<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreWithdrawalRequest;
use App\Http\Resources\WithdrawalResource;
use App\Models\Organization;
use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function __construct(protected WithdrawalService $withdrawals) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $items = $this->org($request)->withdrawals()->latest()->get();

        return ApiResponse::success(WithdrawalResource::collection($items));
    }

    public function store(StoreWithdrawalRequest $request, string $organization): JsonResponse
    {
        $withdrawal = $this->withdrawals->request(
            $this->org($request),
            auth('api')->user(),
            (float) $request->validated('amount'),
            $request->validated('note'),
        );

        return ApiResponse::success(
            new WithdrawalResource($withdrawal),
            'Permintaan penarikan dikirim. Dana ditahan sampai admin memproses transfer.',
            201,
        );
    }

    public function cancel(Request $request, string $organization, string $withdrawal): JsonResponse
    {
        $model = $this->find($request, $withdrawal);

        return ApiResponse::success(
            new WithdrawalResource($this->withdrawals->cancel($model)),
            'Penarikan dibatalkan, dana kembali ke saldo tersedia.',
        );
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }

    protected function find(Request $request, string $id): Withdrawal
    {
        return $this->org($request)->withdrawals()->findOrFail($id);
    }
}
