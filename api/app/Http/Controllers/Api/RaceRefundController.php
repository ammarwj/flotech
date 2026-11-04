<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RaceEntryResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceEntry;
use App\Services\RaceEntryService;
use App\Services\RefundService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The organizer's refund queue for one fun run: who asked, and the verdict.
 *
 * Its own controller rather than more methods on RaceEntryController, and the
 * split is the same one PaymentVerificationController already makes: that list
 * is the start list, read constantly and acted on rarely, while this is a queue
 * of decisions. They also sit behind different middleware — see below — and a
 * controller spanning two guards is one whose docblock has to explain which
 * half of it is protected.
 *
 * > **Behind `org.admin`, not plain `tenant`.** Approving a refund reverses a
 * > wallet credit and may push the organizer's balance negative, which locks
 * > their next withdrawal; a gate operator scanning runners in must not be able
 * > to do it. Same rule as the wallet endpoints and
 * > PaymentVerificationController, and the opposite call from the discount-code
 * > CRUD beside it, which is a price list. Test it with THREE actors on one
 * > payload (owner / `operator` / another org's member → 200/403/403); asserting
 * > the owner succeeds passes identically under plain `tenant`.
 *
 * > **Invariant: approving goes through RefundService, inside one transaction.**
 * > That service is the only thing allowed to move money — it voids the entry,
 * > reverses the ledger row keyed `(race_entry, id)` and gives the place back to
 * > the category. Stamping the verdict without it leaves the organizer credited
 * > for money that went back, which `wallet:audit` cannot see: the ledger stays
 * > internally consistent, it is just one row richer than the truth. Test it by
 * > COMPARING the wallet balance and `race_categories.taken` before and after an
 * > approval; asserting `status === 'refunded'` alone passes even with no
 * > reversal at all.
 *
 * The money does NOT go back to the runner automatically — it sits in the
 * platform's own Midtrans account, so the transfer back is a separate manual
 * step. Every success message here says so, the same wording the super admin's
 * refund screen carries.
 */
class RaceRefundController extends Controller
{
    public function __construct(
        protected RaceEntryService $entries,
        protected RefundService $refunds,
    ) {}

    /**
     * Requests waiting on this organizer, oldest first.
     *
     * Oldest first on purpose, unlike every other list in the app: this is a
     * queue of people waiting for an answer, and newest-first buries whoever
     * has waited longest. Same call `PaymentVerificationController` makes with
     * `payment_proof_uploaded_at`.
     *
     * `decided` rides along in the same payload rather than behind a `?status=`
     * filter: the queue is usually empty, and an empty screen that cannot show
     * what was decided last week reads as a feature that lost its data.
     */
    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $eventModel = $this->event($request, $event);

        return ApiResponse::success([
            'pending' => RaceEntryResource::collection(
                $eventModel->raceEntries()
                    ->awaitingRefundDecision()
                    ->with('category')
                    ->oldest('refund_requested_at')
                    ->get(),
            ),
            'decided' => RaceEntryResource::collection(
                $eventModel->raceEntries()
                    ->whereNotNull('refund_decided_at')
                    ->with('category')
                    ->latest('refund_decided_at')
                    ->limit(50)
                    ->get(),
            ),
        ]);
    }

    /**
     * Grant it: void the entry, reverse the credit, give the place back.
     *
     * The guard is `RefundService`'s own, deliberately not repeated here — it
     * refuses anything not `paid` and anything free, and a second copy of those
     * two rules is one that drifts from the service every other rail refunds
     * through. What this method adds is the open-request check, because granting
     * a refund nobody asked for is the super admin's action, not this one.
     */
    public function approve(Request $request, string $organization, string $event, string $raceEntry): JsonResponse
    {
        $entry = $this->entry($request, $event, $raceEntry);
        $admin = $request->user();

        abort_unless($entry->hasOpenRefundRequest(), 422, 'Tidak ada pengajuan refund yang menunggu keputusan.');

        DB::transaction(function () use ($entry, $admin) {
            // The order is deliberate: the verdict is stamped first so that a
            // failure inside refundRaceEntry() rolls both back together. Either
            // the ledger moved and the row says who approved it, or neither
            // happened — there is no third outcome where one is written.
            $this->entries->markRefundApproved($entry, $admin);
            $this->refunds->refundRaceEntry($entry->fresh(), $admin, $entry->refund_reason);
        });

        return ApiResponse::success(
            new RaceEntryResource($entry->fresh()->load('category')),
            'Refund disetujui. Uangnya belum kembali ke peserta — transfer balik masih dilakukan manual.',
        );
    }

    /**
     * Turn it down. The entry stays paid and keeps its place in the quota.
     *
     * The reason is required for the same reason `standing_adjustments.reason`
     * is NOT NULL: a refusal with no explanation is worse for the person
     * reading it than no feature at all, and they read it on their own page.
     */
    public function reject(Request $request, string $organization, string $event, string $raceEntry): JsonResponse
    {
        $entry = $this->entry($request, $event, $raceEntry);

        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->entries->rejectRefund($entry, $request->user(), $reason);

        return ApiResponse::success(
            new RaceEntryResource($entry->fresh()->load('category')),
            'Pengajuan refund ditolak.',
        );
    }

    private function entry(Request $request, string $eventId, string $entryId): RaceEntry
    {
        return $this->event($request, $eventId)->raceEntries()->findOrFail($entryId);
    }

    private function event(Request $request, string $eventId): Event
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org->events()->findOrFail($eventId);
    }
}
