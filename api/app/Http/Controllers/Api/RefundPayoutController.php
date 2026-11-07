<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RefundPayoutResource;
use App\Models\Organization;
use App\Models\RefundPayout;
use App\Services\RefundPayoutService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The organizer's side of a refund: money coming to them, and owed onward.
 *
 * Hop two of the chain. The platform does not hold the participant's bank
 * details and never has — the organizer is the one with that relationship — so
 * forwarding the refund is theirs to do, and this is where they record it. The
 * participant then reads that status on their own registration page, which is
 * the only thing that tells them where their money is.
 *
 * > **Behind `org.admin`, not plain `tenant`.** Marking a refund forwarded is a
 * > statement about money having been sent, which the participant reads and
 * > acts on; a gate operator scanning runners in must not be able to make it.
 * > Same rule as the wallet endpoints, `RaceRefundController` and
 * > `PaymentVerificationController` — and the opposite call from the
 * > discount-code CRUD, which is a price list. Test it with THREE actors on one
 * > payload (owner / `operator` / another org's member → 200/403/403);
 * > asserting the owner succeeds passes identically under plain `tenant`.
 *
 * This controller CANNOT reach `status`, `amount`, `proof_url` or any of the
 * platform's own transfer columns — each of those has exactly one writer, in
 * `Admin\RefundPayoutController`. The shape `UpdateRaceEntryRequest` keeps for
 * the same reason: a second writer over money state is the drift
 * `MatchResultService` owns both result doors to prevent.
 */
class RefundPayoutController extends Controller
{
    public function __construct(protected RefundPayoutService $payouts) {}

    /**
     * Every refund debt on this organization, newest first.
     *
     * Not split into two lists like the admin queue: the organizer's own screen
     * is read as a history of refunds on their events, and the one row that
     * needs action carries `awaiting_forwarding` to say so. Rows still pending
     * at the platform are included deliberately — that is the organizer's
     * answer to a participant asking where their money is.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return ApiResponse::success(
            RefundPayoutResource::collection(
                $org->refundPayouts()
                    ->with('event')
                    ->latest('created_at')
                    ->limit(200)
                    ->get(),
            ),
        );
    }

    /**
     * Record that it reached the participant, with proof.
     *
     * The guards live in `RefundPayoutService::markForwarded()` — it refuses a
     * row the platform has not paid yet, and a second marking. Both matter: the
     * first would let an organizer claim they forwarded money they had not
     * received, and the participant's page would say it was sent by somebody
     * who did not have it.
     */
    public function forward(Request $request, string $organization, string $refundPayout): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $payout = $org->refundPayouts()->findOrFail($refundPayout);

        $data = $request->validate([
            'forwarded_proof_url' => ['required', 'string', 'max:2048'],
            'forwarded_reference' => ['nullable', 'string', 'max:100'],
        ]);

        $updated = $this->payouts->markForwarded(
            $payout,
            $request->user(),
            $data['forwarded_proof_url'],
            $data['forwarded_reference'] ?? null,
        );

        return ApiResponse::success(
            new RefundPayoutResource($updated->load('event')),
            'Ditandai sudah diteruskan ke peserta. Pesertanya bisa melihatnya di halaman pendaftarannya.',
        );
    }
}
