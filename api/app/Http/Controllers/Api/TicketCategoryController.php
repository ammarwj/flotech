<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\StoreTicketCategoryRequest;
use App\Http\Requests\Ticket\UpdateTicketCategoryRequest;
use App\Http\Resources\TicketCategoryResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketCategory;
use App\Services\PlanGate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketCategoryController extends Controller
{
    public function __construct(protected PlanGate $gate) {}

    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        $categories = $event->ticketCategories()->latest()->get();

        return ApiResponse::success(TicketCategoryResource::collection($categories));
    }

    public function store(StoreTicketCategoryRequest $request, string $organization, string $event): JsonResponse
    {
        $org = $this->org($request);
        $event = $this->findEvent($request, $event);

        if ($denied = $this->ensureTicketsEnabled($org)) {
            return $denied;
        }

        $data = $request->validated();

        if ($denied = $this->ensureWithinTicketLimit($org, $event, (int) ($data['quota'] ?? 0))) {
            return $denied;
        }

        $category = $event->ticketCategories()->create($data);

        return ApiResponse::success(new TicketCategoryResource($category), 'Kategori tiket dibuat', 201);
    }

    public function update(UpdateTicketCategoryRequest $request, string $organization, string $ticketCategory): JsonResponse
    {
        $org = $this->org($request);
        $category = $this->findCategory($org, $ticketCategory);

        if ($denied = $this->ensureTicketsEnabled($org)) {
            return $denied;
        }

        $data = $request->validated();

        if (array_key_exists('quota', $data)) {
            $limitCheck = $this->ensureWithinTicketLimit($org, $category->event, (int) ($data['quota'] ?? 0), $category->id);
            if ($limitCheck) {
                return $limitCheck;
            }
        }

        $category->update($data);

        return ApiResponse::success(new TicketCategoryResource($category->fresh()), 'Kategori tiket diperbarui');
    }

    public function destroy(Request $request, string $organization, string $ticketCategory): JsonResponse
    {
        $category = $this->findCategory($this->org($request), $ticketCategory);

        if ($category->sold > 0) {
            return ApiResponse::error('Kategori dengan tiket terjual tidak bisa dihapus. Nonaktifkan saja.', null, 422);
        }

        $category->delete();

        return ApiResponse::success(null, 'Kategori tiket dihapus');
    }

    protected function ensureTicketsEnabled(Organization $org): ?JsonResponse
    {
        if (! $this->gate->allows($org, 'qr_tickets')) {
            return ApiResponse::error('Fitur tiket QR tidak tersedia di paketmu.', ['feature' => 'qr_tickets'], 403);
        }

        return null;
    }

    /**
     * Enforce the plan's total-tickets-per-event cap across all categories.
     * `newQuota` of 0 means unlimited for this category, which is rejected when
     * the plan itself imposes a finite cap.
     */
    protected function ensureWithinTicketLimit(Organization $org, Event $event, int $newQuota, ?string $excludeId = null): ?JsonResponse
    {
        $limit = $this->gate->limit($org, 'max_tickets_per_event');

        if ($limit === null || $limit === -1) {
            return null; // unlimited
        }

        if ($newQuota <= 0) {
            return ApiResponse::error('Paketmu membatasi jumlah tiket, jadi kuota kategori wajib diisi.', ['feature' => 'max_tickets_per_event'], 422);
        }

        $otherQuota = $event->ticketCategories()
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->sum('quota');

        if ($otherQuota + $newQuota > $limit) {
            return ApiResponse::error(
                "Total kuota tiket melebihi batas paketmu ({$limit}).",
                ['feature' => 'max_tickets_per_event'],
                403,
            );
        }

        return null;
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

    /** Resolve a category whose event belongs to the current org (404 otherwise). */
    protected function findCategory(Organization $org, string $categoryId): TicketCategory
    {
        return TicketCategory::whereHas('event', fn ($q) => $q->where('organization_id', $org->id))
            ->with('event')
            ->findOrFail($categoryId);
    }
}
