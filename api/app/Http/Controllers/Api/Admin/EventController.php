<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ForceEventStatusRequest;
use App\Http\Requests\Admin\UpdateEventDomainRequest;
use App\Http\Resources\AdminEventResource;
use App\Models\Event;
use App\Models\EventPlanOrder;
use App\Models\EventStatusOverride;
use App\Services\DomainService;
use App\Services\EventPlanOrderService;
use App\Support\ApiResponse;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Super-admin view across every organization's events, and the custom domain
 * controls that hang off it.
 *
 * Domains are a super-admin decision with no plan gating: issuing a certificate
 * costs Let's Encrypt quota shared by the whole platform, and it only works once
 * someone outside our control has pointed DNS at this VPS. Neither is something
 * an organizer can self-serve.
 */
class EventController extends Controller
{
    public function __construct(protected DomainService $domains) {}

    /** Paginated, searchable list across all organizations. */
    public function index(Request $request): JsonResponse
    {
        $page = Event::query()
            // Search::anyColumn, not a bare LIKE: LIKE is case-sensitive on
            // Postgres but not on sqlite, so a plain one passes every test and
            // fails only in production.
            ->when($request->query('q'), fn ($query, $q) => Search::anyColumn(
                $query,
                ['events.name', 'events.slug', 'events.custom_domain'],
                (string) $q,
            ))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('organization_id'), fn ($query, $id) => $query->where('organization_id', $id))
            ->when($request->query('domain'), fn ($query, $domain) => match ($domain) {
                'none' => $query->whereNull('custom_domain'),
                'active' => $query->whereNotNull('custom_domain')->whereNotNull('domain_certified_at'),
                'pending' => $query->whereNotNull('custom_domain')
                    ->whereNull('domain_certified_at')
                    ->whereNull('domain_error'),
                'failed' => $query->whereNotNull('custom_domain')
                    ->whereNull('domain_certified_at')
                    ->whereNotNull('domain_error'),
                default => $query,
            })
            ->with(['organization:id,name,slug', 'plan'])
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return ApiResponse::success([
            'items' => AdminEventResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Attach or detach the domain. Does NOT issue a certificate — activation is
     * a separate button because it spends Let's Encrypt quota and only succeeds
     * once the owner's DNS has propagated, which can be hours after this.
     */
    public function updateDomain(UpdateEventDomainRequest $request, Event $event): JsonResponse
    {
        $domain = $request->input('custom_domain');

        $this->domains->assign($event, $domain === null ? null : (string) $domain);

        $event->load(['organization:id,name,slug', 'plan']);

        return ApiResponse::success(
            new AdminEventResource($event),
            $event->custom_domain
                ? 'Domain disimpan. Tekan Aktifkan untuk menerbitkan SSL-nya.'
                : 'Domain dilepas.',
        );
    }

    /** Verify DNS, issue the certificate, and start serving the domain. */
    public function activateDomain(Event $event): JsonResponse
    {
        $ok = $this->domains->issue($event);

        $event->refresh()->load(['organization:id,name,slug', 'plan']);

        // A failed activation is not a server error — DNS that has not
        // propagated yet is the ordinary case, and the admin fixes it by
        // waiting. The reason travels in domain_error on the row itself.
        return $ok
            ? ApiResponse::success(new AdminEventResource($event), 'Domain aktif. SSL sudah terbit.')
            : ApiResponse::error(
                $event->domain_error ?: 'Aktivasi domain gagal.',
                ['custom_domain' => [$event->domain_error ?: 'Aktivasi domain gagal.']],
                422,
            );
    }

    /** Stop serving the domain and delete its certificate. */
    public function destroyDomain(Event $event): JsonResponse
    {
        $this->domains->release($event);

        $event->load(['organization:id,name,slug', 'plan']);

        return ApiResponse::success(new AdminEventResource($event), 'Domain dicabut dan sertifikatnya dihapus.');
    }

    /**
     * Force `events.status` straight past `Event::TRANSITIONS`.
     *
     * The escape hatch for the one dead end the state machine has on purpose:
     * `ongoing` has no entry back to `open`/`registration_closed` in
     * TRANSITIONS, because walking an event backwards through its own
     * lifecycle is not a move an organizer should be able to make on a whim.
     * But a fat-fingered "Berlangsung" on an event that is still mid-registration
     * has no self-service undo then either — this is that undo, and it is
     * deliberately not exposed to the organizer themselves: the party who
     * benefits from bypassing a guard is not the one who should decide whether
     * bypassing it is warranted. Same reasoning as reassignPlan().
     *
     * Every target is allowed, not just "backward" ones — restricting it to a
     * fixed set of reversals would need a second table of "sanctioned" moves to
     * keep in step with TRANSITIONS, and the next mistake might not be the one
     * this one anticipated. What keeps it from becoming a free-for-all is the
     * ledger: every override requires a typed reason and is written to
     * `event_status_overrides`, reusing the exact shape `standing_adjustments`
     * already uses for a human-entered number nobody can audit without it.
     *
     * Routes through the same side effects `EventController::transition()`
     * applies on every ordinary move, so a forced status is indistinguishable
     * from a normal one afterwards:
     * - `status_before_cancel` is cleared unless the target IS `cancelled` —
     *   otherwise a stale snapshot from some earlier cancellation could decide
     *   where a later `cancelled` → restore lands, same guard transition() has.
     * - `attachPaidDomain()` runs, so forcing a draft out of `draft` still picks
     *   up a domain it was already paid for instead of leaving it stuck.
     */
    public function forceStatus(ForceEventStatusRequest $request, Event $event): JsonResponse
    {
        $data = $request->validated();
        $from = $event->status;

        DB::transaction(function () use ($event, $data, $from, $request) {
            $event->update([
                'status' => $data['status'],
                'status_before_cancel' => $data['status'] === 'cancelled' ? $from : null,
            ]);

            EventStatusOverride::create([
                'event_id' => $event->id,
                'from_status' => $from,
                'to_status' => $data['status'],
                'reason' => $data['reason'],
                'created_by' => $request->user()?->id,
            ]);
        });

        $this->attachPaidDomain($event);

        $event->refresh()->load(['organization:id,name,slug', 'plan']);

        return ApiResponse::success(
            new AdminEventResource($event),
            "Status dipaksa dari \"{$from}\" ke \"{$data['status']}\".",
        );
    }

    /**
     * Mirror of EventController::attachPaidDomain() — same idempotent "hand
     * over a domain already paid for, now that a status change has made the
     * event eligible" logic, kept here rather than shared because the organizer
     * controller's copy is `protected` on a different class.
     */
    protected function attachPaidDomain(Event $model): void
    {
        if ($model->custom_domain !== null || $model->status === 'draft') {
            return;
        }

        $order = EventPlanOrder::where('domain_event_id', $model->id)
            ->where('status', 'paid')
            ->whereNotNull('domain_fulfilled_at')
            ->whereNotNull('domain_name')
            ->latest('paid_at')
            ->first();

        if ($order) {
            app(EventPlanOrderService::class)->attachDomain($model, $order);
        }
    }
}
