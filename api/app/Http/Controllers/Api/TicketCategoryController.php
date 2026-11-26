<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesEventScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\StoreTicketCategoryRequest;
use App\Http\Requests\Ticket\UpdateTicketCategoryRequest;
use App\Http\Resources\TicketCategoryResource;
use App\Models\Event;
use App\Models\TicketCategory;
use App\Services\Catalog;
use App\Services\PlanGate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketCategoryController extends Controller
{
    use ResolvesEventScope;

    public function __construct(protected PlanGate $gate) {}

    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->scopedEvent($request, $event);

        $categories = $event->ticketCategories()->with('days')->latest()->get();

        return ApiResponse::success(TicketCategoryResource::collection($categories));
    }

    public function store(StoreTicketCategoryRequest $request, string $organization, string $event): JsonResponse
    {
        $event = $this->scopedEvent($request, $event);

        if ($denied = $this->ensureTicketsEnabled($event)) {
            return $denied;
        }

        $data = $request->validated();
        $dates = $data['dates'] ?? [];
        unset($data['dates']);

        $category = $event->ticketCategories()->create($data);

        if ($denied = $this->syncDays($category, $event, $dates)) {
            // Nothing was sold yet, so the half-made category is safe to drop —
            // leaving it behind would show the organizer a per-day category
            // with no days, which is the one state nothing can sell from.
            $category->delete();

            return $denied;
        }

        return ApiResponse::success(
            new TicketCategoryResource($category->fresh()->load('days')),
            'Kategori tiket dibuat',
            201,
        );
    }

    public function update(UpdateTicketCategoryRequest $request, string $organization, string $ticketCategory): JsonResponse
    {
        $category = $this->findCategory($request, $ticketCategory);

        if ($denied = $this->ensureTicketsEnabled($category->event)) {
            return $denied;
        }

        $data = $request->validated();
        $dates = array_key_exists('dates', $data) ? $data['dates'] ?? [] : null;
        unset($data['dates']);

        // The mode shapes the ticket rows that have already been issued, so it
        // is frozen once anything sold — same snapshot reasoning as
        // `participant_type`. `array_key_exists` is what keeps an organizer
        // whose category is selling from being locked out of renaming it.
        if (array_key_exists('day_mode', $data)
            && $data['day_mode'] !== $category->day_mode
            && $category->sold > 0) {
            return ApiResponse::error(
                'Mode hari tidak bisa diubah karena sudah ada tiket terjual.',
                ['day_mode' => ['Sudah ada tiket terjual untuk kategori ini.']],
                422,
            );
        }

        $category->update($data);
        $category->refresh();

        if ($dates !== null && $denied = $this->syncDays($category, $category->event, $dates)) {
            return $denied;
        }

        // A category switched to `none` keeps no days: its tickets carry no
        // date, so a leftover row would be a date nothing can ever sell.
        if (! $category->usesDays()) {
            $category->days()->delete();
        }

        return ApiResponse::success(
            new TicketCategoryResource($category->fresh()->load('days')),
            'Kategori tiket diperbarui',
        );
    }

    /**
     * Bring a category's sold days in line with what the organizer picked.
     *
     * The same sync contract as syncPlayers(): a date that is sent is kept, one
     * that is not is removed. Three refusals, each 422 on the `dates` field so
     * the form can bind it inline:
     *
     * - a date outside the event's own range, which nothing could attend;
     * - no dates at all on a day-selling category — "no dates" is zero days,
     *   never "all of them", and a category with none can sell nothing;
     * - dropping a date somebody already holds a ticket for. Adding a date is
     *   always allowed, which is the half that makes this a lock and not a
     *   freeze.
     *
     * @param  list<string>  $dates
     */
    protected function syncDays(TicketCategory $category, Event $event, array $dates): ?JsonResponse
    {
        if (! $category->usesDays()) {
            $category->days()->delete();

            return null;
        }

        $dates = array_values(array_unique($dates));
        sort($dates);

        if ($dates === []) {
            return ApiResponse::error(
                'Pilih tanggal yang dijual untuk kategori ini.',
                ['dates' => ['Pilih minimal satu tanggal.']],
                422,
            );
        }

        if ($outside = array_diff($dates, $event->days())) {
            return ApiResponse::error(
                'Ada tanggal di luar rentang tanggal event.',
                ['dates' => ['Tanggal '.implode(', ', $outside).' di luar rentang event.']],
                422,
            );
        }

        $existing = $category->days()->get();
        $dropped = $existing->reject(fn ($day) => in_array($day->event_date->toDateString(), $dates, true));

        $sold = $dropped->filter(fn ($day) => $day->sold > 0);

        if ($sold->isNotEmpty()) {
            $labels = $sold->map(fn ($day) => $day->event_date->toDateString())->implode(', ');

            return ApiResponse::error(
                'Ada tanggal yang sudah terjual dan tidak bisa dihapus.',
                ['dates' => ['Tanggal '.$labels.' sudah ada tiket terjualnya.']],
                422,
            );
        }

        $dropped->each->delete();

        foreach ($dates as $date) {
            $category->days()->firstOrCreate(['event_date' => $date]);
        }

        return null;
    }

    public function destroy(Request $request, string $organization, string $ticketCategory): JsonResponse
    {
        $category = $this->findCategory($request, $ticketCategory);

        if ($category->sold > 0) {
            return ApiResponse::error('Kategori dengan tiket terjual tidak bisa dihapus. Nonaktifkan saja.', null, 422);
        }

        $category->delete();

        return ApiResponse::success(null, 'Kategori tiket dihapus');
    }

    /**
     * Whether this event may sell spectator tickets at all.
     *
     * Two refusals, and the shapes differ because the organizer can act on the
     * difference. The plan is a purchase decision — 403 + `errors.feature`, the
     * ordinary organizer door, which `isPlanLimitError()` turns into a toast
     * pointing at the plan. The sport is not: a fun run has nothing to sell a
     * spectator ticket *to*. It is run on a road or in a park with no gate to
     * close, and its runners already carry a QR of their own —
     * `race_entries.qr_code`, minted by markPaid() and scanned at
     * /race-scan. So that one is a plain 422 with no `errors.feature` key:
     * swallowed into a plan toast it would send somebody to buy an upgrade that
     * cannot change the answer.
     *
     * Asked through `Catalog::isCompetitive()`, whose docblock names itself the
     * landing place for exactly this gate — the alternative is a third copy of
     * the `scoring` negation it exists to prevent.
     */
    protected function ensureTicketsEnabled(Event $event): ?JsonResponse
    {
        if (! Catalog::isCompetitive($event->sport_type)) {
            return ApiResponse::error(
                'Cabang ini tidak menjual tiket penonton. Peserta lomba mendaftar lewat halaman Peserta Lomba dan sudah membawa QR-nya sendiri.',
                null,
                422,
            );
        }

        if (! $this->gate->allows($event, 'qr_tickets')) {
            return ApiResponse::error(
                'Fitur tiket tidak tersedia di paket event ini.',
                ['feature' => 'qr_tickets'],
                403,
            );
        }

        return null;
    }

    /**
     * Resolve a category by id, scoped to the event the request was already
     * authorized for. `event.perm.ticketing` resolves that event (and, for an
     * operator, the `operator` attribute) from this very route parameter via
     * `EventOperatorAttribution::resolveEventFrom()` before this ever runs —
     * so the `event` attribute is always trustworthy here, for both an org
     * member and an operator. Falls back to the org's own events for safety
     * if something calls this without that middleware having run.
     */
    protected function findCategory(Request $request, string $categoryId): TicketCategory
    {
        $event = $request->attributes->get('event');
        $org = $this->org($request);

        return TicketCategory::query()
            ->when($event instanceof Event, fn ($q) => $q->where('event_id', $event->id))
            ->when(! ($event instanceof Event) && $org, fn ($q) => $q->whereHas('event', fn ($q2) => $q2->where('organization_id', $org->id)))
            ->with(['event', 'days'])
            ->findOrFail($categoryId);
    }
}
