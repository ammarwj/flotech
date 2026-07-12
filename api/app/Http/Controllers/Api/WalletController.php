<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\Organization;
use App\Services\WalletService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(protected WalletService $wallet) {}

    public function show(Request $request, string $organization): JsonResponse
    {
        $wallet = $this->wallet->forOrganization($this->org($request));

        return ApiResponse::success(new WalletResource($wallet));
    }

    /** The ledger is unbounded, so this one paginates. */
    public function transactions(Request $request, string $organization): JsonResponse
    {
        $wallet = $this->wallet->forOrganization($this->org($request));

        $page = $wallet->transactions()
            ->with('event')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('category'), fn ($q, $category) => $q->where('category', $category))
            ->latest()
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return ApiResponse::success([
            'items' => WalletTransactionResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }
}
