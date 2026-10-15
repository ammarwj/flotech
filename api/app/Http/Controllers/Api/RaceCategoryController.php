<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Race\StoreRaceCategoryRequest;
use App\Http\Requests\Race\UpdateRaceCategoryRequest;
use App\Http\Resources\RaceCategoryResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceCategory;
use App\Services\Catalog;
use App\Services\PlanGate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The categories a fun run sells entries in, and their price per sale window.
 *
 * Shaped on TicketCategoryController, including the two refusals that matter:
 * a half-made category is deleted rather than left behind, and a category
 * somebody has entered cannot be deleted. What it does NOT copy is a
 * `day_mode`-style freeze — there is no stored shape here that an existing
 * entry depends on, because every entry carries its own price snapshot.
 */
class RaceCategoryController extends Controller
{
    public function __construct(protected PlanGate $gate) {}

    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        // `prices` and the event's windows both, for the same reason every
        // endpoint publishing a plan has to load `plan.features`: the resource
        // renders a price schedule from them, and a missing relation reports an
        // empty one — which reads as a category with no prices rather than a
        // relation somebody forgot.
        $categories = $event->raceCategories()
            ->with(['prices', 'event.racePeriods'])
            ->get();

        return ApiResponse::success(RaceCategoryResource::collection($categories));
    }

    public function store(StoreRaceCategoryRequest $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        if ($denied = $this->ensureRaceEvent($event)) {
            return $denied;
        }

        $data = $request->validated();
        $prices = $data['prices'] ?? [];
        unset($data['prices']);

        $category = $event->raceCategories()->create([
            ...$data,
            'slug' => $this->uniqueSlug($event, $data['name'], null),
            'sort_order' => (int) $event->raceCategories()->max('sort_order') + 1,
        ]);

        if ($denied = $this->syncPrices($category, $event, $prices)) {
            // Nothing has entered yet, so the half-made category is safe to
            // drop — the same reasoning TicketCategoryController gives: leaving
            // it behind shows the organizer a category with no prices, which is
            // the one state nothing can be sold from.
            $category->delete();

            return $denied;
        }

        return ApiResponse::success(
            new RaceCategoryResource($category->fresh()->load(['prices', 'event.racePeriods'])),
            'Kategori lomba dibuat',
            201,
        );
    }

    public function update(UpdateRaceCategoryRequest $request, string $organization, string $raceCategory): JsonResponse
    {
        $category = $this->findCategory($this->org($request), $raceCategory);

        if ($denied = $this->ensureRaceEvent($category->event)) {
            return $denied;
        }

        $data = $request->validated();
        // array_key_exists, not `?? []`: a client that does not send `prices`
        // at all must leave the stored matrix alone, while an empty array means
        // "clear it". Same guard, same reason as `officials` on the
        // registration form.
        $prices = array_key_exists('prices', $data) ? $data['prices'] : null;
        unset($data['prices']);

        if (isset($data['name'])) {
            $data['slug'] = $this->uniqueSlug($category->event, $data['name'], $category->id);
        }

        $category->update($data);

        if ($prices !== null && $denied = $this->syncPrices($category, $category->event, $prices)) {
            return $denied;
        }

        return ApiResponse::success(
            new RaceCategoryResource($category->fresh()->load(['prices', 'event.racePeriods'])),
            'Kategori lomba diperbarui',
        );
    }

    /**
     * Bring a category's prices in line with what the organizer typed.
     *
     * The same sync contract as syncDays(): a period that is sent is kept at
     * the price given, one that is not is removed — and removing it means "not
     * sold in that window", which is the whole point of the absent row (see the
     * migration).
     *
     * Two refusals, both 422 on `prices` so the form binds them inline:
     *
     * - a period that is not one of this event's windows. Validation cannot
     *   catch it: the FormRequest has no event.
     * - removing the price of a window somebody has already entered under.
     *   Adding or repricing a window is always allowed, which is the half that
     *   makes this a lock and not a freeze — and repricing deliberately does
     *   NOT touch entries already taken, because each carries its own snapshot.
     *
     * @param  list<array{race_period_id: string, price: float|int|string}>  $prices
     */
    protected function syncPrices(RaceCategory $category, Event $event, array $prices): ?JsonResponse
    {
        $valid = $event->racePeriods()->pluck('id')->all();
        $sent = array_column($prices, 'race_period_id');

        if ($unknown = array_diff($sent, $valid)) {
            return ApiResponse::error(
                'Ada periode yang bukan milik event ini.',
                ['prices' => ['Periode '.implode(', ', $unknown).' tidak dikenal.']],
                422,
            );
        }

        if (count(array_unique($sent)) !== count($sent)) {
            // `distinct` is load-bearing the same way it is in
            // UpdateLandingStatsRequest: two rows for one period would
            // updateOrCreate twice and silently keep the last, with a green
            // toast. Checked here rather than in the request only because the
            // message can name the field the same way the other two do.
            return ApiResponse::error(
                'Ada periode yang dikirim lebih dari sekali.',
                ['prices' => ['Satu periode hanya boleh punya satu harga.']],
                422,
            );
        }

        $dropped = array_diff($category->prices()->pluck('race_period_id')->all(), $sent);

        if ($dropped !== []) {
            $taken = $category->entries()
                ->whereIn('race_period_id', $dropped)
                ->whereNotIn('status', ['cancelled', 'expired'])
                ->exists();

            if ($taken) {
                return ApiResponse::error(
                    'Ada periode yang sudah ada pesertanya dan harganya tidak bisa dihapus.',
                    ['prices' => ['Periode yang sudah ada pendaftarnya tidak bisa dihapus dari daftar harga.']],
                    422,
                );
            }

            $category->prices()->whereIn('race_period_id', $dropped)->delete();
        }

        foreach ($prices as $row) {
            $category->prices()->updateOrCreate(
                ['race_period_id' => $row['race_period_id']],
                ['price' => $row['price']],
            );
        }

        return null;
    }

    public function destroy(Request $request, string $organization, string $raceCategory): JsonResponse
    {
        $category = $this->findCategory($this->org($request), $raceCategory);

        // `taken`, not a count of paid entries: a pending entry is holding a
        // place and its runner is mid-checkout. Deactivating is the way to
        // close a category that has history, exactly as for ticket categories.
        if ($category->taken > 0) {
            return ApiResponse::error(
                'Kategori yang sudah ada pesertanya tidak bisa dihapus. Nonaktifkan saja.',
                null,
                422,
            );
        }

        $category->delete();

        return ApiResponse::success(null, 'Kategori lomba dihapus');
    }

    /**
     * Refuse on an event that is not a fun run.
     *
     * 403 with `errors.feature`, the shape every organizer door uses — but the
     * key names the sport, not a plan entitlement, because that is what is
     * missing. A competition event has `event_categories` instead, and silently
     * accepting a race category there would leave rows nothing ever reads.
     */
    protected function ensureRaceEvent(Event $event): ?JsonResponse
    {
        if (Catalog::scoringOf($event->sport_type) !== 'none') {
            return ApiResponse::error(
                'Kategori lomba hanya untuk event cabang tanpa pertandingan.',
                ['feature' => 'race_categories'],
                403,
            );
        }

        return null;
    }

    protected function uniqueSlug(Event $event, string $source, ?string $ignoreId): string
    {
        $base = Str::slug($source) ?: Str::lower(Str::random(6));
        $slug = $base;
        $i = 1;

        while (
            $event->raceCategories()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
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
    protected function findCategory(Organization $org, string $categoryId): RaceCategory
    {
        return RaceCategory::whereHas('event', fn ($q) => $q->where('organization_id', $org->id))
            ->with(['event.racePeriods', 'prices'])
            ->findOrFail($categoryId);
    }
}
