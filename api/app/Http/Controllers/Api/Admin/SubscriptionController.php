<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PaymentRails;
use App\Services\SubscriptionService;
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
class SubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptions,
        protected PaymentRails $rails,
    ) {}

    public function index(): JsonResponse
    {
        $pending = Subscription::query()
            ->awaitingVerification()
            ->with(['plan', 'organization'])
            ->latest('payment_proof_uploaded_at')
            ->get();

        return ApiResponse::success(SubscriptionResource::collection($pending));
    }

    public function approve(Request $request, Subscription $subscription): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $this->subscriptions->approveProof($subscription, $admin);

        return ApiResponse::success(
            new SubscriptionResource($subscription->fresh()->load(['plan', 'organization'])),
            'Pembayaran diterima. Paket sudah aktif.',
        );
    }

    public function reject(Request $request, Subscription $subscription): JsonResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Alasan penolakan wajib diisi.',
        ])['reason'];

        $this->subscriptions->rejectProof($subscription, $reason, $this->rails->deadline());

        return ApiResponse::success(
            new SubscriptionResource($subscription->fresh()->load(['plan', 'organization'])),
            'Bukti ditolak. Organizer dapat mengunggah ulang.',
        );
    }
}
