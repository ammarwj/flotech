<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateEventDomainRequest;
use App\Http\Resources\AdminEventResource;
use App\Models\Event;
use App\Services\DomainService;
use App\Support\ApiResponse;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
