<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RaceEntryResource;
use App\Http\Resources\TeamResource;
use App\Http\Resources\TicketOrderResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceEntry;
use App\Models\RaceEntryTransfer;
use App\Models\Team;
use App\Models\TicketOrder;
use App\Services\PaymentRails;
use App\Services\RaceEntryService;
use App\Services\RaceTransferService;
use App\Services\RegistrationService;
use App\Services\TicketService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manual-transfer payments waiting on a human, per event.
 *
 * Behind `org.admin` rather than plain `tenant`: approving a receipt issues a
 * valid ticket (or admits a paying team, or a paying runner) on nothing but
 * someone's say-so, so a gate operator must not be able to do it. Same rule as
 * the wallet endpoints.
 *
 * Three rails of income rendered side by side, sharing nothing but the
 * HasManualPayment trait. They are independent on purpose: a queue per model
 * would make the organizer check three screens to find out whether anyone is
 * waiting on them.
 */
class PaymentVerificationController extends Controller
{
    public function __construct(
        protected TicketService $tickets,
        protected RegistrationService $registration,
        protected PaymentRails $rails,
        protected RaceEntryService $raceEntries,
        protected RaceTransferService $raceTransfers,
    ) {}

    /**
     * Everything awaiting verification for this event, every rail of income.
     */
    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $eventModel = $this->event($request, $event);

        return ApiResponse::success([
            'tickets' => TicketOrderResource::collection(
                $eventModel->ticketOrders()->awaitingVerification()->with('category')->latest('payment_proof_uploaded_at')->get(),
            ),
            'teams' => TeamResource::collection(
                $eventModel->teams()->awaitingVerification()->with('category')->latest('payment_proof_uploaded_at')->get(),
            ),
            // Empty on every competition event, and present unconditionally
            // anyway: a key whose absence depends on the sport is one the
            // client has to defend against in three places instead of none.
            'race_entries' => RaceEntryResource::collection(
                $eventModel->raceEntries()->awaitingVerification()->with('category')->latest('payment_proof_uploaded_at')->get(),
            ),
            // The fifth rail, and the only one whose approval moves somebody
            // rather than just settling money — see approveRaceTransfer().
            // Present unconditionally like the key above it, for the reason
            // written there.
            'race_transfers' => RaceEntryTransfer::where('event_id', $eventModel->id)
                ->awaitingVerification()
                ->with(['entry', 'fromCategory', 'toCategory'])
                ->latest('payment_proof_uploaded_at')
                ->get()
                ->map(fn (RaceEntryTransfer $t) => $this->transferRow($t))
                ->all(),
        ]);
    }

    /**
     * Receipts this event has already accepted, every rail, newest first.
     *
     * The same three keys as index() so one page can render either list without
     * a second shape to learn, and the same `scopeVerified()` the super admin's
     * plan log reads — "already accepted" must not mean two different things on
     * two screens. A row is in exactly one of the two lists: the scopes disagree
     * on one column, `verified_at`, so nothing can fall out of both.
     *
     * `verifier` is eager-loaded here and nowhere else: an org admin ruled on
     * these, and which of their admins it was is the one fact a queue cannot
     * answer yet. Capped at 50 per rail rather than paginated, for the reason
     * the plan log gives — this answers "did we already accept that transfer?",
     * asked minutes to days later, and the permanent record is the receipt PDF.
     */
    public function history(Request $request, string $organization, string $event): JsonResponse
    {
        $eventModel = $this->event($request, $event);

        return ApiResponse::success([
            'tickets' => TicketOrderResource::collection(
                $eventModel->ticketOrders()->verified()->with(['category', 'verifier'])->limit(50)->get(),
            ),
            'teams' => TeamResource::collection(
                $eventModel->teams()->verified()->with(['category', 'verifier'])->limit(50)->get(),
            ),
            'race_entries' => RaceEntryResource::collection(
                $eventModel->raceEntries()->verified()->with(['category', 'verifier'])->limit(50)->get(),
            ),
            'race_transfers' => RaceEntryTransfer::where('event_id', $eventModel->id)
                ->verified()
                ->with(['entry', 'fromCategory', 'toCategory', 'verifier'])
                ->limit(50)
                ->get()
                ->map(fn (RaceEntryTransfer $t) => $this->transferRow($t))
                ->all(),
        ]);
    }

    public function approveTicket(Request $request, string $organization, string $event, string $order): JsonResponse
    {
        $model = $this->ticketOrder($request, $event, $order);

        $this->tickets->approveProof($model, $request->user());

        return ApiResponse::success(
            new TicketOrderResource($model->fresh()->load('category')),
            'Pembayaran diterima. Tiket sudah berlaku.',
        );
    }

    public function rejectTicket(Request $request, string $organization, string $event, string $order): JsonResponse
    {
        $model = $this->ticketOrder($request, $event, $order);

        $this->tickets->rejectProof($model, $this->reason($request), $this->rails->deadline());

        return ApiResponse::success(
            new TicketOrderResource($model->fresh()->load('category')),
            'Bukti ditolak. Pembeli dapat mengunggah ulang.',
        );
    }

    public function approveTeam(Request $request, string $organization, string $event, string $team): JsonResponse
    {
        $model = $this->team($request, $event, $team);

        $this->registration->approveProof($model, $request->user());

        return ApiResponse::success(
            new TeamResource($model->fresh()->load('category')),
            'Pembayaran diterima.',
        );
    }

    public function rejectTeam(Request $request, string $organization, string $event, string $team): JsonResponse
    {
        $model = $this->team($request, $event, $team);

        $this->registration->rejectProof($model, $this->reason($request), $this->rails->deadline());

        return ApiResponse::success(
            new TeamResource($model->fresh()->load('category')),
            'Bukti ditolak. Tim dapat mengunggah ulang.',
        );
    }

    public function approveRaceEntry(Request $request, string $organization, string $event, string $raceEntry): JsonResponse
    {
        $model = $this->raceEntry($request, $event, $raceEntry);

        $this->raceEntries->approveProof($model, $request->user());

        return ApiResponse::success(
            new RaceEntryResource($model->fresh()->load('category')),
            'Pembayaran diterima. Pendaftaran sudah lunas.',
        );
    }

    public function rejectRaceEntry(Request $request, string $organization, string $event, string $raceEntry): JsonResponse
    {
        $model = $this->raceEntry($request, $event, $raceEntry);

        $this->raceEntries->rejectProof($model, $this->reason($request), $this->rails->deadline());

        return ApiResponse::success(
            new RaceEntryResource($model->fresh()->load('category')),
            'Bukti ditolak. Peserta dapat mengunggah ulang.',
        );
    }

    /**
     * Accept a top-up for a category move.
     *
     * The approval is what MOVES the runner: `approveProof()` settles the
     * transfer, which swaps `race_entries.race_category_id`, releases the
     * source place and drops the category-stamped bib. So this is the one arm
     * on this screen whose refusal matters beyond the money — and the message
     * says so, because an org admin who has just clicked it is about to look at
     * a start list that changed.
     */
    public function approveRaceTransfer(Request $request, string $organization, string $event, string $transfer): JsonResponse
    {
        $model = $this->raceTransfer($request, $event, $transfer);

        $this->raceTransfers->approveProof($model, $request->user());

        return ApiResponse::success(
            $this->transferRow($model->fresh()->load(['entry', 'fromCategory', 'toCategory'])),
            'Pembayaran diterima. Peserta sudah pindah kategori.',
        );
    }

    public function rejectRaceTransfer(Request $request, string $organization, string $event, string $transfer): JsonResponse
    {
        $model = $this->raceTransfer($request, $event, $transfer);

        $this->raceTransfers->rejectProof($model, $this->reason($request), $this->rails->deadline());

        return ApiResponse::success(
            $this->transferRow($model->fresh()->load(['entry', 'fromCategory', 'toCategory'])),
            'Bukti ditolak. Peserta dapat mengunggah ulang.',
        );
    }

    /**
     * One pending move, as this queue reads it.
     *
     * Inline rather than a JsonResource, the same call PublicRaceController
     * makes for the runner's panel: this is a handful of fields read by one
     * screen. What it must carry is BOTH category names — an admin approving a
     * transfer is about to change who stands where, and a row saying only
     * "Pindah kategori" cannot be checked against the receipt in front of them.
     *
     * @return array<string, mixed>
     */
    private function transferRow(RaceEntryTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'runner_name' => $transfer->entry?->full_name,
            'runner_email' => $transfer->entry?->email,
            'race_entry_id' => $transfer->race_entry_id,
            'from_category' => $transfer->fromCategory?->name,
            'to_category' => $transfer->toCategory?->name,
            'period_name' => $transfer->period_name,
            'amount' => (float) $transfer->amount,
            'gross_amount' => (float) $transfer->gross_amount,
            'status' => $transfer->status,
            'payment_method' => $transfer->payment_method,
            'awaiting_verification' => $transfer->isAwaitingVerification(),
            'payment_proof_url' => $transfer->payment_proof_url,
            'payment_proof_uploaded_at' => $transfer->payment_proof_uploaded_at,
            'rejected_reason' => $transfer->rejected_reason,
            'verified_at' => $transfer->verified_at,
            'verified_by' => $transfer->relationLoaded('verifier') ? $transfer->verifier?->full_name : null,
            'reason' => $transfer->reason,
        ];
    }

    private function reason(Request $request): string
    {
        return $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];
    }

    private function ticketOrder(Request $request, string $eventId, string $orderId): TicketOrder
    {
        return $this->event($request, $eventId)->ticketOrders()->findOrFail($orderId);
    }

    private function team(Request $request, string $eventId, string $teamId): Team
    {
        return $this->event($request, $eventId)->teams()->findOrFail($teamId);
    }

    private function raceEntry(Request $request, string $eventId, string $entryId): RaceEntry
    {
        return $this->event($request, $eventId)->raceEntries()->findOrFail($entryId);
    }

    /**
     * Scoped through `event_id`, which is the reason that column is on
     * `race_entry_transfers` at all: going through `raceEntries()` would need a
     * join to prove a transfer belongs to this organization, and a tenant check
     * that needs a join is one somebody eventually writes without it.
     */
    private function raceTransfer(Request $request, string $eventId, string $transferId): RaceEntryTransfer
    {
        return RaceEntryTransfer::where('event_id', $this->event($request, $eventId)->id)
            ->findOrFail($transferId);
    }

    /**
     * Resolve an event scoped to the current organization.
     */
    private function event(Request $request, string $eventId): Event
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org->events()->findOrFail($eventId);
    }
}
