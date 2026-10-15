<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Race\RacePeriodsRequest;
use App\Http\Resources\RacePeriodResource;
use App\Models\Event;
use App\Models\Organization;
use App\Services\Catalog;
use App\Support\ApiResponse;
use App\Support\RacePricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The sale windows of one fun run, read and replaced as a set.
 *
 * A sync endpoint rather than an apiResource, because the windows only mean
 * anything as a sequence: "does this overlap the next one" cannot be answered
 * one row at a time, and an organizer shifting early bird back a day is editing
 * the whole run of them. Same shape as `PUT plans/{plan}/features` and
 * `syncOfficialRoles`.
 */
class RacePeriodController extends Controller
{
    public function __construct(protected RacePricing $pricing) {}

    public function index(Request $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        return ApiResponse::success($this->payload($event));
    }

    /**
     * Replace the whole set.
     *
     * The sync contract every other list in this codebase uses: a row carrying
     * an `id` is an update, one without is new, and anything the client left
     * out is deleted. Which is also where the one real danger is — a deleted
     * window would take its price rows with it (cascade) and strand the entries
     * bought under it, so a window somebody has entered under cannot be
     * dropped. Moving one is still allowed: each entry carries its own price
     * and period name, so nothing already sold changes.
     */
    public function sync(RacePeriodsRequest $request, string $organization, string $event): JsonResponse
    {
        $event = $this->findEvent($request, $event);

        if ($denied = $this->ensureRaceEvent($event)) {
            return $denied;
        }

        $rows = $request->validated()['periods'];

        if ($denied = $this->assertNoOverlap($rows)) {
            return $denied;
        }

        $keep = array_values(array_filter(array_column($rows, 'id')));

        if ($denied = $this->assertDroppedAreFree($event, $keep)) {
            return $denied;
        }

        DB::transaction(function () use ($event, $rows, $keep) {
            $event->racePeriods()->whereNotIn('id', $keep)->delete();

            foreach (array_values($rows) as $i => $row) {
                $attributes = [
                    'name' => $row['name'],
                    'starts_at' => $row['starts_at'],
                    'ends_at' => $row['ends_at'],
                    // The order they were typed, which the model only reads to
                    // break ties between windows starting at the same instant
                    // — the relation sorts by `starts_at` first.
                    'sort_order' => $i,
                ];

                if (! empty($row['id'])) {
                    $event->racePeriods()->whereKey($row['id'])->update($attributes);

                    continue;
                }

                $event->racePeriods()->create($attributes);
            }
        });

        return ApiResponse::success($this->payload($event->fresh()), 'Periode pendaftaran disimpan');
    }

    /**
     * Refuse two windows that cover the same instant.
     *
     * Enforced on write rather than tolerated on read, and that is what lets
     * RacePricing stay a plain "first match wins": two live windows would make
     * the price depend on row order, which nothing on either screen shows. The
     * comparison is pairwise over at most twelve rows, so there is no index to
     * worry about.
     *
     * @param  list<array{name: string, starts_at: string, ends_at: string}>  $rows
     */
    protected function assertNoOverlap(array $rows): ?JsonResponse
    {
        $windows = array_map(
            fn (array $row) => [Carbon::parse($row['starts_at']), Carbon::parse($row['ends_at']), $row['name']],
            $rows,
        );

        foreach ($windows as $i => [$startA, $endA, $nameA]) {
            foreach (array_slice($windows, $i + 1) as [$startB, $endB, $nameB]) {
                // Inclusive bounds, so touching ends overlap too: a window
                // ending 23:59:59 and the next starting 23:59:59 would both
                // contain that second, which is precisely the ambiguity
                // RacePeriod::contains() cannot resolve.
                if ($startA->lte($endB) && $startB->lte($endA)) {
                    return ApiResponse::error(
                        "Periode \"{$nameA}\" dan \"{$nameB}\" saling tumpang tindih.",
                        ['periods' => ['Tanggal antar periode tidak boleh tumpang tindih.']],
                        422,
                    );
                }
            }
        }

        return null;
    }

    /**
     * Refuse to delete a window somebody has already entered under.
     *
     * Reads `race_entries.race_period_id` rather than the price rows: a price
     * row can be removed once nobody holds it, but an entry is a person who
     * paid, and the FK is `nullOnDelete` — so dropping the window would quietly
     * sever the entry from the period its receipt names. Cancelled and expired
     * entries do not count: nobody is holding those.
     *
     * @param  list<string>  $keep
     */
    protected function assertDroppedAreFree(Event $event, array $keep): ?JsonResponse
    {
        $dropped = $event->racePeriods()->whereNotIn('id', $keep)->pluck('name', 'id');

        if ($dropped->isEmpty()) {
            return null;
        }

        $used = $event->raceEntries()
            ->whereIn('race_period_id', $dropped->keys())
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->pluck('race_period_id')
            ->unique();

        if ($used->isEmpty()) {
            return null;
        }

        $names = $used->map(fn ($id) => $dropped[$id] ?? $id)->implode(', ');

        return ApiResponse::error(
            "Periode {$names} sudah ada pendaftarnya dan tidak bisa dihapus.",
            ['periods' => ["Periode {$names} sudah ada pendaftarnya."]],
            422,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Event $event): array
    {
        $event->loadMissing('racePeriods');

        // Resolved once and threaded down, which is why RacePeriodResource
        // takes it as an argument — see its constructor.
        $current = $this->pricing->periodAt($event);

        return ['periods' => RacePeriodResource::listOf($event->racePeriods, $current?->id)];
    }

    protected function ensureRaceEvent(Event $event): ?JsonResponse
    {
        if (Catalog::scoringOf($event->sport_type) !== 'none') {
            return ApiResponse::error(
                'Periode pendaftaran hanya untuk event cabang tanpa pertandingan.',
                ['feature' => 'race_periods'],
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
        return $this->org($request)->events()->with('racePeriods')->findOrFail($eventId);
    }
}
