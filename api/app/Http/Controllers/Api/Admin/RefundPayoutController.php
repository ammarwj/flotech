<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\RefundPayoutResource;
use App\Models\RefundPayout;
use App\Services\RefundPayoutService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The platform's side of a refund: transfers it still owes organizers.
 *
 * This screen is the answer to a gap that was silent by construction. Granting
 * a refund reverses the organizer's wallet credit immediately, but the buyer's
 * money is in the platform's own Midtrans account — so until somebody here
 * transfers it, the refund exists only as a balance that went down. Before this
 * controller nothing notified a super admin, and race entries were not even
 * listed on `/admin/payments`, so the debt was invisible even to somebody
 * looking for it.
 *
 * > **Behind `superadmin`, like the withdrawal queue beside it.** This money
 * > leaves the platform's own account; an organizer must not be able to mark
 * > their own refund transferred. The organizer's half of the chain is
 * > `Api\RefundPayoutController`, behind `org.admin`, and it can only touch
 * > `forwarded_*`.
 *
 * `pending` is oldest-first, the same call `RaceRefundController::index()` and
 * `PaymentVerificationController` make: somebody is waiting on each of these,
 * and newest-first buries whoever has waited longest. `completed` rides along
 * in the same payload rather than behind a `?status=` filter, because the queue
 * is usually empty and a screen that cannot show last week's transfers reads as
 * one that lost its data.
 */
class RefundPayoutController extends Controller
{
    public function __construct(protected RefundPayoutService $payouts) {}

    public function index(Request $request): JsonResponse
    {
        $relations = ['organization', 'event'];

        return ApiResponse::success([
            'pending' => RefundPayoutResource::collection(
                RefundPayout::awaitingTransfer()->with($relations)->oldest('created_at')->get(),
            ),
            'completed' => RefundPayoutResource::collection(
                RefundPayout::where('status', 'completed')
                    ->with($relations)
                    ->latest('completed_at')
                    ->limit(100)
                    ->get(),
            ),
        ]);
    }

    /**
     * Record the transfer out, with proof.
     *
     * The proof is required, not optional: this is real money leaving the
     * platform on somebody's word, and the organizer reads it on their own
     * screen to answer the participant chasing them. The same reasoning
     * `standing_adjustments.reason` is NOT NULL for.
     *
     * The guard lives in `RefundPayoutService::complete()`, deliberately not
     * repeated here — a second copy of "already completed" is one that drifts
     * from the service every other caller goes through.
     */
    public function complete(Request $request, string $refundPayout): JsonResponse
    {
        $payout = RefundPayout::findOrFail($refundPayout);

        $data = $request->validate([
            'proof_url' => ['required', 'string', 'max:2048'],
            'transfer_reference' => ['nullable', 'string', 'max:100'],
            'admin_note' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->payouts->complete(
            $payout,
            $request->user(),
            $data['proof_url'],
            $data['transfer_reference'] ?? null,
            $data['admin_note'] ?? null,
        );

        return ApiResponse::success(
            new RefundPayoutResource($updated->load(['organization', 'event'])),
            'Transfer refund ditandai selesai. Penyelenggara sudah diberi tahu untuk meneruskannya ke peserta.',
        );
    }
}
