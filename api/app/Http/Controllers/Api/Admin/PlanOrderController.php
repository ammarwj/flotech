<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventPlanOrderResource;
use App\Models\EventPlanOrder;
use App\Models\User;
use App\Services\PaymentRails;
use App\Services\EventPlanOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manual plan payments waiting on us.
 *
 * The counterpart to PaymentVerificationController, one level up: that one is
 * an org admin ruling on money paid into their *own* account, this one is a
 * super admin ruling on money paid into the platform's. Accepting here
 * activates a paid plan on nothing but someone's say-so, and the organizer
 * being credited is exactly the party who uploaded the receipt — so it can
 * never sit behind `tenant`, however convenient that would be.
 */
class PlanOrderController extends Controller
{
    public function __construct(
        protected EventPlanOrderService $orders,
        protected PaymentRails $rails,
    ) {}

    public function index(): JsonResponse
    {
        $pending = EventPlanOrder::query()
            ->awaitingVerification()
            ->with(['plan', 'organization'])
            ->latest('payment_proof_uploaded_at')
            ->get();

        return ApiResponse::success(EventPlanOrderResource::collection($pending));
    }

    public function approve(Request $request, EventPlanOrder $planOrder): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $this->orders->approveProof($planOrder, $admin);

        return ApiResponse::success(
            new EventPlanOrderResource($planOrder->fresh()->load(['plan', 'organization'])),
            'Pembayaran diterima. Paket siap dipakai untuk satu event.',
        );
    }

    public function reject(Request $request, EventPlanOrder $planOrder): JsonResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Alasan penolakan wajib diisi.',
        ])['reason'];

        $this->orders->rejectProof($planOrder, $reason, $this->rails->deadline());

        return ApiResponse::success(
            new EventPlanOrderResource($planOrder->fresh()->load(['plan', 'organization'])),
            'Bukti ditolak. Organizer dapat mengunggah ulang.',
        );
    }
}
