<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Jobs\ReleaseEventFundsJob;
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
        $events = $this->org($request)->events()->with('categories')->withCount('teams')->latest()->get();

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
        $categories = $data['categories'] ?? [];
        unset($data['categories']);

        $data['slug'] = $this->uniqueSlug($org, $data['slug'] ?? $data['name']);
        $data['status'] = 'draft';

        $event = $org->events()->create($data);
        $this->syncCategories($event, $categories);

        return ApiResponse::success(new EventResource($event->load('categories')), 'Event dibuat', 201);
    }

    public function show(Request $request, string $organization, string $event): JsonResponse
    {
        return ApiResponse::success(new EventResource($this->find($request, $event)->load('categories')->loadCount('teams')));
    }

    public function update(UpdateEventRequest $request, string $organization, string $event): JsonResponse
    {
        $model = $this->find($request, $event);
        $wasFinished = $model->status === 'finished';

        $data = $request->validated();
        $categories = $data['categories'] ?? null;
        unset($data['categories']);

        $model->update($data);

        if ($categories !== null) {
            $this->syncCategories($model, $categories);
        }

        // Closing an event releases the ticket & registration money the
        // platform has been holding for this organizer.
        if (! $wasFinished && $model->status === 'finished') {
            ReleaseEventFundsJob::dispatch($model->id)->afterCommit();
        }

        return ApiResponse::success(new EventResource($model->load('categories')), 'Event diperbarui');
    }

    /**
     * Full-replace the event's categories from the submitted list. Rows carrying
     * an `id` are updated in place; new rows are created; categories no longer in
     * the list are removed (which cascades their teams and matches). A format
     * preset can seed a category's bracket_config ("Liga 2 Putaran" = league +
     * 2 legs) — what the organizer sent still wins.
     *
     * @param  array<int, array<string, mixed>>  $categories
     */
    protected function syncCategories(Event $event, array $categories): void
    {
        $keep = [];

        foreach (array_values($categories) as $i => $cat) {
            $defaults = Catalog::formatDefaults($cat['tournament_format'] ?? null);
            $bracket = $cat['bracket_config'] ?? null;
            if ($defaults !== []) {
                $bracket = [...$defaults, ...($bracket ?? [])];
            }

            $attributes = [
                'name' => $cat['name'],
                'slug' => $this->uniqueCategorySlug($event, $cat['slug'] ?? $cat['name'], $cat['id'] ?? null),
                'tournament_format' => $cat['tournament_format'],
                'bracket_config' => $bracket,
                'registration_fee' => $cat['registration_fee'] ?? 0,
                'max_teams' => $cat['max_teams'] ?? null,
                'sort_order' => $i,
            ];

            $existing = ! empty($cat['id']) ? $event->categories()->find($cat['id']) : null;
            if ($existing) {
                $existing->update($attributes);
                $keep[] = $existing->id;
            } else {
                $keep[] = $event->categories()->create($attributes)->id;
            }
        }

        $event->categories()->whereNotIn('id', $keep)->delete();
    }

    protected function uniqueCategorySlug(Event $event, string $source, ?string $ignoreId): string
    {
        $base = Str::slug($source) ?: Str::lower(Str::random(6));
        $slug = $base;
        $i = 1;

        while (
            $event->categories()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
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
