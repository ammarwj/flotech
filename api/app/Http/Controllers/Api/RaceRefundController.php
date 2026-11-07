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

        // `refundPayout` on the decided list only, and the asymmetry is the
        // point: a payout row cannot exist until a refund is granted, so
        // loading it beside the pending queue would be a query per row that
        // always answers null. Without it on this side `refund_transfer` is
        // absent — `whenLoaded` never fires — and the screen says "Disetujui"
        // forever over money that has already been transferred twice, which is
        // exactly the silence this feature was built to end.
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
                    ->with(['category', 'refundPayout'])
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
     * Refund a runner the organizer decided to refund, with nobody having asked.
     *
     * The duplicate-payment case, and it is the common one: somebody pays
     * twice because the first attempt looked like it failed, and they are not
     * going to file a request for a registration they do not know they have.
     * Without this the only way out was the platform admin's own refund screen,
     * which makes a support ticket out of an organizer's own money.
     *
     * One endpoint, not a second refund engine: the money still moves through
     * `RefundService`, in one transaction, with the verdict stamped beside it —
     * the same pairing `approve()` makes. What differs is only which of the two
     * `RaceEntryService` stampers runs, and therefore whether
     * `refund_requested_at` ends up null.
     *
     * > **It refuses an entry with an OPEN request**, which reads backwards
     * > until you look at what the two write: `approve()` answers the runner
     * > and leaves their reason standing as the question, while this one writes
     * > the organizer's reason over `refund_reason`. Run here, a pending
     * > request would have the runner's own words replaced by the organizer's
     * > and their page would show an answer to a question it can no longer
     * > print. The refusal names the queue, so there is one door for that row
     * > and it is the right one.
     *
     * The reason is required for the same reason `reject()`'s is: the runner
     * reads it on their own page, and a refund appearing on a registration
     * somebody paid for with no word about why is worse than no feature at all.
     */
    public function issue(Request $request, string $organization, string $event, string $raceEntry): JsonResponse
    {
        $entry = $this->entry($request, $event, $raceEntry);
        $admin = $request->user();

        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        if ($entry->hasOpenRefundRequest()) {
            return ApiResponse::error(
                'Peserta ini sudah mengajukan refund. Setujui atau tolak pengajuannya di antrean.',
                null,
                422,
            );
        }

        // Asked here and nowhere else, because RefundService refuses an entry
        // that is not `paid` and one that is free but knows nothing about the
        // start line. Not folded into that service: a checked-in TICKET is
        // already refused there by its own used-check, and a race entry's
        // equivalent column had no writer when it was written.
        if ($entry->checked_in_at !== null) {
            return ApiResponse::error(
                'Peserta ini sudah check-in di lokasi, pendaftarannya tidak bisa direfund.',
                null,
                422,
            );
        }

        DB::transaction(function () use ($entry, $admin, $reason) {
            $this->entries->markRefundIssued($entry, $admin, $reason);
            $this->refunds->refundRaceEntry($entry->fresh(), $admin, $reason);
        });

        return ApiResponse::success(
            new RaceEntryResource($entry->fresh()->load('category')),
            'Pendaftaran direfund. Uangnya belum kembali ke peserta — transfer balik masih dilakukan manual.',
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
