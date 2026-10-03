<?php

namespace App\Services;

use App\Models\LandingStatSetting;
use App\Models\User;
use App\Support\LandingMetrics;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the landing "Proof" counters: the LandingMetrics catalog merged with
 * whatever a super admin overrode in `landing_stat_settings`.
 *
 * The catalog decides which metrics exist; the table only decides their label,
 * whether they show, and in what order. See LandingMetrics for why the split
 * runs that way.
 */
class LandingStatService
{
    /**
     * Spelled once and shared by the public reader (PublicStatController) and
     * the admin writer (put()). Two spellings means a `forget` on the wrong key
     * fails silently and presents as "my save was lost".
     */
    public const CACHE_KEY = 'public_stats';

    /** @var array{views: int, unique_visitors: int}|null */
    private ?array $viewTotals = null;

    public function __construct(private readonly EventViewService $views) {}

    /**
     * One aggregate row over `event_view_daily` feeds both traffic metrics, so
     * switching both on must still be one query.
     *
     * Memoized PER INSTANCE — deliberately not static, and deliberately not on a
     * controller: Laravel keeps resolved controllers, and the services injected
     * into them, alive across requests, so a longer-lived memo would serve
     * yesterday's numbers (the DisciplineService memo note in CLAUDE.md is the
     * cautionary tale). A request-scoped memo is exactly as long as it needs to be.
     *
     * @return array{views: int, unique_visitors: int}
     */
    public function viewTotals(): array
    {
        return $this->viewTotals ??= $this->views->platformTotals();
    }

    /**
     * Catalog ⊕ overrides, ordered. A null column means "inherit", which is why
     * all three are nullable in the migration.
     *
     * @return list<array<string, mixed>>
     */
    public function effective(): array
    {
        $overrides = LandingStatSetting::query()->get()->keyBy('metric_key');

        $rows = [];

        foreach (LandingMetrics::all() as $key => $metric) {
            // Rows whose catalog entry is gone are ignored, never deleted —
            // the same courtesy the retired `basic` plan row gets, so a metric
            // pulled for one release and restored in the next keeps its settings.
            $row = $overrides->get($key);

            $rows[] = [
                'key' => $key,
                'label' => $row?->label ?? $metric['label'],
                'default_label' => $metric['label'],
                'is_active' => $row?->is_active ?? $metric['active'],
                'sort_order' => $row?->sort_order ?? $metric['sort'],
                'is_overridden' => $row !== null,
                'resolve' => $metric['resolve'],
            ];
        }

        // On `sort_order` alone. PHP's sort has been stable since 8.0, so catalog
        // order is the tiebreak — without that, two metrics sharing a sort_order
        // would swap places on every cache miss and the strip would reshuffle
        // itself for no reason anyone could reproduce.
        usort($rows, fn (array $a, array $b) => $a['sort_order'] <=> $b['sort_order']);

        return $rows;
    }

    /**
     * Every metric with its number, including the inactive ones — the admin page
     * needs a preview of what switching one on would show.
     *
     * @return list<array<string, mixed>>
     */
    public function effectiveWithValues(): array
    {
        return array_map(function (array $row) {
            $resolve = $row['resolve'];
            unset($row['resolve']);

            return $row + ['value' => (int) $resolve($this)];
        }, $this->effective());
    }

    /**
     * What the landing page gets: active metrics only, three keys each.
     *
     * A value that genuinely is 0 still ships as 0. There is deliberately no
     * "hide when zero" — a server-side zero filter would change the column count
     * from request to request and would silently drop the metric somebody just
     * switched on.
     *
     * @return list<array{key: string, label: string, value: int}>
     */
    public function publicList(): array
    {
        $out = [];

        foreach ($this->effective() as $row) {
            if (! $row['is_active']) {
                continue;
            }

            $resolve = $row['resolve'];

            $out[] = [
                'key' => $row['key'],
                'label' => $row['label'],
                'value' => (int) $resolve($this),
            ];
        }

        return $out;
    }

    /**
     * Persist overrides. Keys are assumed validated against the catalog
     * (UpdateLandingStatsRequest), which is what stops a typo from saving
     * happily and then being read by nothing.
     *
     * A metric whose three columns are back to matching the catalog loses its
     * row entirely. That is the other half of "nullable means inherit": leaving
     * a row that merely repeats today's defaults would freeze them, and a deploy
     * changing a default would never reach production again.
     *
     * @param  list<array<string, mixed>>  $metrics
     */
    public function put(array $metrics, ?User $actor = null): void
    {
        $catalog = LandingMetrics::all();

        foreach ($metrics as $metric) {
            $key = (string) $metric['metric_key'];

            if (! isset($catalog[$key])) {
                continue;
            }

            // A blank label is "inherit", not a blank heading on the strip — the
            // admin form sends '' from an emptied input, same convention as the
            // numeric fields in event-form.tsx.
            $label = array_key_exists('label', $metric) ? ($metric['label'] ?: null) : null;
            $active = array_key_exists('is_active', $metric) && $metric['is_active'] !== null
                ? (bool) $metric['is_active']
                : null;
            $sort = array_key_exists('sort_order', $metric) && $metric['sort_order'] !== null
                ? (int) $metric['sort_order']
                : null;

            $matchesCatalog = $label === null
                && ($active === null || $active === $catalog[$key]['active'])
                && ($sort === null || $sort === $catalog[$key]['sort']);

            if ($matchesCatalog) {
                LandingStatSetting::query()->where('metric_key', $key)->delete();

                continue;
            }

            LandingStatSetting::updateOrCreate(
                ['metric_key' => $key],
                [
                    'label' => $label,
                    'is_active' => $active,
                    'sort_order' => $sort,
                    'updated_by' => $actor?->id,
                ],
            );
        }

        // Here, not in the controller — the write path owns its own
        // invalidation, the same shape as PlatformSettings::put() → flush().
        Cache::forget(self::CACHE_KEY);
    }
}
