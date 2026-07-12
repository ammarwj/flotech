<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\CompleteWithdrawalRequest;
use App\Http\Requests\Wallet\RejectWithdrawalRequest;
use App\Http\Resources\WithdrawalResource;
use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The payout queue. Transfers are made by hand in a banking app; these
 * endpoints only record what the admin did.
 */
class WithdrawalController extends Controller
{
    public function __construct(protected WithdrawalService $withdrawals) {}

    public function index(Request $request): JsonResponse
    {
        $items = Withdrawal::with('organization')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->get();

        return ApiResponse::success(WithdrawalResource::collection($items));
    }

    public function show(Request $request, string $withdrawal): JsonResponse
    {
        $model = Withdrawal::with('organization')->findOrFail($withdrawal);

        return ApiResponse::success(new WithdrawalResource($model));
    }

    public function process(Request $request, string $withdrawal): JsonResponse
    {
        $model = $this->withdrawals->process(Withdrawal::findOrFail($withdrawal), auth('api')->user());

        return ApiResponse::success(new WithdrawalResource($model), 'Penarikan ditandai sedang diproses');
    }

    public function complete(CompleteWithdrawalRequest $request, string $withdrawal): JsonResponse
    {
        $model = $this->withdrawals->complete(
            Withdrawal::findOrFail($withdrawal),
            auth('api')->user(),
            $request->validated('proof_url'),
            $request->validated('transfer_reference'),
            $request->validated('admin_note'),
        );

        return ApiResponse::success(new WithdrawalResource($model), 'Penarikan ditandai selesai');
    }

    public function reject(RejectWithdrawalRequest $request, string $withdrawal): JsonResponse
    {
        $model = $this->withdrawals->reject(
            Withdrawal::findOrFail($withdrawal),
            auth('api')->user(),
            $request->validated('admin_note'),
        );

        return ApiResponse::success(new WithdrawalResource($model), 'Penarikan ditolak, dana dikembalikan ke saldo organizer');
    }
}
