<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreBankAccountRequest;
use App\Http\Requests\Wallet\UpdateBankAccountRequest;
use App\Http\Resources\BankAccountResource;
use App\Models\BankAccount;
use App\Models\Organization;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    public function index(Request $request, string $organization): JsonResponse
    {
        $accounts = $this->org($request)->bankAccounts()->latest()->get();

        return ApiResponse::success(BankAccountResource::collection($accounts));
    }

    public function store(StoreBankAccountRequest $request, string $organization): JsonResponse
    {
        $org = $this->org($request);

        // Exactly one primary account. A new one takes over as the payout
        // destination; past withdrawals keep their own snapshot.
        $org->bankAccounts()->update(['is_primary' => false]);

        $account = $org->bankAccounts()->create([
            ...$request->validated(),
            'is_primary' => true,
        ]);

        return ApiResponse::success(new BankAccountResource($account), 'Rekening bank ditambahkan', 201);
    }

    public function update(UpdateBankAccountRequest $request, string $organization, string $bankAccount): JsonResponse
    {
        $account = $this->find($request, $bankAccount);
        $account->update($request->validated());

        return ApiResponse::success(new BankAccountResource($account->fresh()), 'Rekening bank diperbarui');
    }

    public function destroy(Request $request, string $organization, string $bankAccount): JsonResponse
    {
        $org = $this->org($request);
        $account = $this->find($request, $bankAccount);

        if ($org->withdrawals()->whereIn('status', ['pending', 'processing'])->exists()) {
            return ApiResponse::error('Ada penarikan yang sedang diproses ke rekening ini.', null, 422);
        }

        $account->delete();

        return ApiResponse::success(null, 'Rekening bank dihapus');
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }

    protected function find(Request $request, string $id): BankAccount
    {
        return $this->org($request)->bankAccounts()->findOrFail($id);
    }
}
