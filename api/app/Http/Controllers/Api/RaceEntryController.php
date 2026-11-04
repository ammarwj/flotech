<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Race\UpdateRaceEntryRequest;
use App\Http\Resources\RaceEntryResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceEntry;
use App\Services\RaceEntryService;
use App\Support\ApiResponse;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The organizer's list of runners, and the two corrections they may make to one.
 *
 * > **Invariant: this door touches the runner, never the money.** `update()`
 * > writes contact details, form answers and the bib; it cannot reach `status`,
 * > `paid_at`, `price`, the fee columns, `qr_code`, `checked_in_at` or
 * > `race_category_id` — see UpdateRaceEntryRequest, where each absence is
 * > spelled out. Every one of those has exactly one writer already (the runner
 * > through PublicRaceController, an org admin ruling on proof through
 * > PaymentVerificationController, a super admin refunding through
 * > RefundService, the hourly sweep, RaceEntryService::checkIn()), and a second
 * > way to move money state is the drift MatchResultService owns both result
 * > doors to prevent.
 *
 * There is **no hard delete**, and that is the shape of the feature rather than
 * a missing endpoint. A settled entry carries a wallet credit keyed
 * `(race_entry, id)`, a numbered receipt and a QR already on somebody's phone;
 * deleting the row would leave a balance claiming money for a registration that
 * no longer exists, which is the `EventController::destroy()` hazard again.
 * `cancel()` gives the place back and keeps the row, so nothing ever disappears
 * from a report — and it routes through RaceEntryService, which is the only
 * thing allowed to decrement `taken`.
 */
class RaceEntryController extends Controller
{
    public function __construct(protected RaceEntryService $entries) {}

    /**
     * The filter values the status pills may send.
     *
     * `awaiting_verification` is NOT a sixth status column value — see
     * HasManualPayment for why that state is derived from the proof columns and
     * must never be slipped into `status`, where it would walk past every guard
     * written as `in_array($status, [...])`. It is a filter *question* the
     * screen already asks (the headline counts it), answered here by the
     * trait's own scope. Keeping it out of this list would print a number with
     * no way to see the rows behind it.
     */
    public const AWAITING = 'awaiting_verification';

    /** @var list<string> */
    public const STATUSES = ['pending', 'paid', 'cancelled', 'expired', 'refunded', self::AWAITING];

    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        $filters = $request->validate([
            // Rejected rather than ignored: a typo'd pill value would silently
            // return the unfiltered list under a selected tab.
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'category_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->filtered($event, $filters)
            ->with(['category', 'documents'])
            ->latest()
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));

        return ApiResponse::success([
            'items' => RaceEntryResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                // How many rows the status pill is actually showing, which is
                // what the pager prints. Deliberately distinct from
                // `summary.total` — see summary() for why they differ.
                'count' => $page->total(),
            ],
            'summary' => $this->summary($event, $filters),
            // The category dropdown's options. From the event's own categories
            // rather than from the rows on screen — a category nobody has
            // entered yet still belongs in the list, and filtering by it is how
            // an organizer finds out it has sold nothing.
            'categories' => $event->raceCategories()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
                ->all(),
        ]);
    }

    /**
     * Correct one runner's details.
     *
     * The organizer's valve, and it is the only one: a runner who typed their
     * name wrong, or whose phone changed, has no other way to fix it once the
     * registration window has shut — `MyTeamController` freezes a participant's
     * own edits then, and this list is where the organizer is already standing.
     *
     * Not gated on the window, nor on the entry's status, and both are
     * deliberate. The window is the participant's clock, not the organizer's —
     * that asymmetry is exactly what makes freezing the participant side safe
     * to enforce tightly. And a `cancelled` entry still wants its typo fixed:
     * the row stays in the report, and a wrong name there is wrong forever.
     * What a status *could* gate here is money, and money is not reachable from
     * this endpoint at all.
     *
     * Answers go through the same RaceEntryService pair the public form uses,
     * so a field this event does not ask for cannot be stored from here and a
     * required one cannot be emptied. `array_key_exists`, not `??`: an absent
     * key leaves the stored answers alone, while `custom_fields: {}` really
     * does clear them — the same reading `officials` takes on a team update.
     */
    public function update(UpdateRaceEntryRequest $request, string $organization, string $event, string $raceEntry): JsonResponse
    {
        $eventModel = $this->findEvent($request, $event);
        $entry = $eventModel->raceEntries()->findOrFail($raceEntry);

        $data = $request->validated();

        if (array_key_exists('custom_fields', $data)) {
            $answers = $data['custom_fields'] ?? [];

            // Checked against the answers as they will be STORED, which is the
            // whole payload merged over nothing — not over the row. An organizer
            // sending `custom_fields` is replacing the set, so a required field
            // left out of that payload really is missing.
            $errors = $this->entries->formErrors(
                $eventModel,
                $answers,
                // The runner's own uploads, untouched by this door: a required
                // document already in storage must not read as missing just
                // because this request does not mention it.
                $entry->documents()->pluck('document_type')->all(),
            );

            if ($errors !== []) {
                return ApiResponse::error('Ada data yang belum lengkap.', $errors, 422);
            }

            $data['custom_fields'] = $this->entries->cleanAnswers($eventModel, $answers);
        }

        $entry->update($data);

        return ApiResponse::success(
            new RaceEntryResource($entry->fresh()->load(['category', 'documents'])),
            'Data peserta diperbarui',
        );
    }

    /**
     * Cancel an entry and give its place back.
     *
     * The answer to "hapus peserta", and the row survives it. What an organizer
     * wants is the quota freed and the runner off the start list; what they do
     * not want — and would get from a delete — is a wallet credit pointing at
     * nothing, a receipt number issued for a row that cannot be found, and a QR
     * already on a phone that now scans to a 404.
     *
     * The guard lives in RaceEntryService::cancel(), which refuses `paid`,
     * `cancelled` and `refunded` by value and is therefore idempotent: a double
     * click cannot hand back a place nobody was holding. A paid entry needs a
     * refund, which is RefundService's job because reversing the ledger is, so
     * this says so rather than failing silently — the service's early return
     * would otherwise answer 200 over an entry still holding its place.
     */
    public function cancel(Request $request, string $organization, string $event, string $raceEntry): JsonResponse
    {
        $entry = $this->findEvent($request, $event)
            ->raceEntries()
            ->findOrFail($raceEntry);

        if ($entry->status === 'paid') {
            return ApiResponse::error(
                'Pendaftaran yang sudah lunas tidak bisa dibatalkan dari sini. Ajukan pengembalian dana ke admin platform.',
                null,
                422,
            );
        }

        if (in_array($entry->status, ['cancelled', 'refunded'], true)) {
            return ApiResponse::error('Pendaftaran ini sudah tidak aktif.', null, 422);
        }

        $this->entries->cancel($entry);

        return ApiResponse::success(
            new RaceEntryResource($entry->fresh()->load(['category', 'documents'])),
            'Pendaftaran dibatalkan dan kuotanya dikembalikan',
        );
    }

    /**
     * The cards above the list: the event's standing tally.
     *
     * Counted over the SAME filtered set the page comes out of, but before
     * paginating. These used to be counted on the client from whatever rows had
     * loaded, beside a total that came from the paginator — two numbers printed
     * in one sentence and measured against different populations. Unpaginated
     * that merely looked right; the first page of 25 would have read "120
     * pendaftar · 25 lunas" with nothing on screen saying which to believe. The
     * same reasoning as `effective_payment_method`: one sentence, one answer,
     * and the server is the only side that has it.
     *
     * Status is the one filter deliberately ignored, the same call
     * TicketOrderController::summary() makes — these are what the event has
     * taken, and narrowing them would have the "Dibatalkan" pill print "12
     * pendaftar · 0 lunas" over a list doing exactly what was asked.
     *
     * Revenue is `price`, the organizer's net, NOT `gross_amount`: the gateway
     * fee and the service fee on top of it are the platform's and the
     * processor's, and `WalletService::creditRaceEntry()` credits exactly this
     * column. A card reading higher than the wallet it feeds is a number an
     * organizer has to reconcile by hand.
     *
     * Each `clone`d so an aggregate does not consume the builder. The awaiting
     * count goes through HasManualPayment's own scope rather than being spelled
     * out again: it is four conditions, one of them `verified_at IS NULL`, and a
     * second copy here would drift from the queue screen that acts on it — the
     * whole reason that trait derives the state instead of storing it.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, float|int>
     */
    protected function summary(Event $event, array $filters): array
    {
        $all = $this->filtered($event, [...$filters, 'status' => null]);
        $paid = (clone $all)->where('status', 'paid');

        return [
            'total' => (clone $all)->count(),
            'paid' => (clone $paid)->count(),
            'awaiting_verification' => (clone $all)->awaitingVerification()->count(),
            // Of the paid ones, and that pairing is the point: an unpaid entry
            // has no code to scan, so "N dari M" is only a ratio against the
            // runners who can actually turn up.
            'checked_in' => (clone $paid)->whereNotNull('checked_in_at')->count(),
            'revenue' => (float) (clone $paid)->sum('price'),
        ];
    }

    /**
     * The filtered query, built once and read by both the list and the counts.
     * Two copies of these clauses would drift, and the drift would show up as a
     * headline that disagrees with the rows beneath it.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<RaceEntry>
     */
    protected function filtered(Event $event, array $filters): Builder
    {
        return RaceEntry::query()
            ->where('event_id', $event->id)
            ->when(
                $filters['category_id'] ?? null,
                fn ($q, $id) => $q->where('race_category_id', $id),
            )
            ->when(
                ($filters['status'] ?? null) === self::AWAITING,
                fn ($q) => $q->awaitingVerification(),
                fn ($q) => $q->when(
                    $filters['status'] ?? null,
                    fn ($w, $status) => $w->where('status', $status),
                ),
            )
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(
                // Search::anyColumn rather than `ilike`: the latter is
                // Postgres-only so the branch could not be tested, and it does
                // not escape wildcards — one `%` typed into the box returns
                // every runner.
                fn ($w) => Search::anyColumn($w, ['full_name', 'email', 'phone'], (string) $term)
            ));
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }

    protected function findEvent(Request $request, string $eventId): Event
    {
        return $this->org($request)->events()->findOrFail($eventId);
    }
}
