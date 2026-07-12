<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\Organization;
use App\Services\Catalog;
use App\Services\PlanGate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EventController extends Controller
{
    public function __construct(protected PlanGate $gate) {}

    public function index(Request $request): JsonResponse
    {
        $events = $this->org($request)->events()->withCount('teams')->latest()->get();

        return ApiResponse::success(EventResource::collection($events));
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        $org = $this->org($request);

        $activeCount = $org->events()->whereNotIn('status', ['finished', 'cancelled'])->count();
        if (! $this->gate->withinLimit($org, 'max_active_events', $activeCount)) {
            return ApiResponse::error('Batas event aktif untuk paketmu sudah tercapai.', ['feature' => 'max_active_events'], 403);
        }

        $data = $request->validated();
        $data['slug'] = $this->uniqueSlug($org, $data['slug'] ?? $data['name']);
        $data['status'] = 'draft';

        // A format preset can carry a starting bracket_config ("Liga 2 Putaran"
        // = engine league + legs 2). What the organizer sent still wins.
        $defaults = Catalog::formatDefaults($data['tournament_format'] ?? null);
        if ($defaults !== []) {
            $data['bracket_config'] = [...$defaults, ...($data['bracket_config'] ?? [])];
        }

        $event = $org->events()->create($data);

        return ApiResponse::success(new EventResource($event), 'Event dibuat', 201);
    }

    public function show(Request $request, string $organization, string $event): JsonResponse
    {
        return ApiResponse::success(new EventResource($this->find($request, $event)->loadCount('teams')));
    }

    public function update(UpdateEventRequest $request, string $organization, string $event): JsonResponse
    {
        $model = $this->find($request, $event);
        $model->update($request->validated());

        return ApiResponse::success(new EventResource($model), 'Event diperbarui');
    }

    public function destroy(Request $request, string $organization, string $event): JsonResponse
    {
        $this->find($request, $event)->delete();

        return ApiResponse::success(null, 'Event dihapus');
    }

    public function publish(Request $request, string $organization, string $event): JsonResponse
    {
        $model = $this->find($request, $event);
        $model->update(['status' => 'open']);

        return ApiResponse::success(new EventResource($model), 'Event dipublikasikan');
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }

    /**
     * Resolve an event scoped to the current organization (404 otherwise).
     */
    protected function find(Request $request, string $eventId): Event
    {
        return $this->org($request)->events()->findOrFail($eventId);
    }

    protected function uniqueSlug(Organization $org, string $source): string
    {
        $base = Str::slug($source) ?: Str::lower(Str::random(8));
        $slug = $base;
        $i = 1;

        while ($org->events()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
