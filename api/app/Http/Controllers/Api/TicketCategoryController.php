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

        if ($denied = $this->ensureTicketsEnabled($event)) {
            return $denied;
        }

        $data = $request->validated();

        $category = $event->ticketCategories()->create($data);

        return ApiResponse::success(new TicketCategoryResource($category), 'Kategori tiket dibuat', 201);
    }

    public function update(UpdateTicketCategoryRequest $request, string $organization, string $ticketCategory): JsonResponse
    {
        $org = $this->org($request);
        $category = $this->findCategory($org, $ticketCategory);

        if ($denied = $this->ensureTicketsEnabled($category->event)) {
            return $denied;
        }

        $category->update($request->validated());

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

    protected function ensureTicketsEnabled(Event $event): ?JsonResponse
    {
        if (! $this->gate->allows($event, 'qr_tickets')) {
            return ApiResponse::error(
                'Fitur tiket tidak tersedia di paket event ini.',
                ['feature' => 'qr_tickets'],
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
