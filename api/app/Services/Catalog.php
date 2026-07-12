<?php

namespace App\Services;

use App\Models\ConfigOption;
use App\Models\Sport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The single reader for admin-managed configuration: sports (and their stat
 * columns) plus the reference options — tournament formats, tiebreakers, draw
 * methods, knockout rounds, sponsor tiers.
 *
 * Read on nearly every request (validation rules, standings, schedule), so it's
 * cached wholesale and flushed explicitly on every admin write and by the
 * seeders. Flushing is explicit rather than via model events because
 * DatabaseSeeder runs WithoutModelEvents.
 */
class Catalog
{
    private const KEY = 'catalog';

    /** @var array<string, mixed>|null in-request memo */
    private static ?array $memo = null;

    public static function flush(): void
    {
        self::$memo = null;
        Cache::forget(self::KEY);
    }

    /**
     * @return array{sports: array<int, array<string, mixed>>, options: array<string, array<int, array<string, mixed>>>}
     */
    public static function data(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        return self::$memo = Cache::rememberForever(self::KEY, function () {
            $sports = Sport::with('stats')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Sport $s) => [
                    'slug' => $s->slug,
                    'name' => $s->name,
                    'color' => $s->color,
                    'icon' => $s->icon,
                    'scoring' => $s->scoring,
                    'default_match_minutes' => $s->default_match_minutes,
                    'stats' => $s->stats->map(fn ($stat) => [
                        'key' => $stat->stat_key,
                        'label' => $stat->label,
                        'short' => $stat->short,
                        'role' => $stat->role,
                        'fair_play_weight' => $stat->fair_play_weight,
                    ])->values()->all(),
                ])
                ->values()
                ->all();

            $options = ConfigOption::where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->groupBy('group')
                ->map(fn ($rows) => $rows->map(fn (ConfigOption $o) => [
                    'key' => $o->key,
                    'label' => $o->label,
                    'description' => $o->description,
                    'meta' => $o->meta ?? [],
                ])->values()->all())
                ->all();

            return ['sports' => $sports, 'options' => $options];
        });
    }

    // ---- Sports ----

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function sports(): Collection
    {
        return collect(self::data()['sports']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function sport(?string $slug): ?array
    {
        return self::sports()->firstWhere('slug', $slug);
    }

    /**
     * The stat columns of a sport, primary first. Empty for an unknown sport.
     *
     * @return array<int, array{key: string, label: string, short: string, role: ?string, fair_play_weight: int}>
     */
    public static function statColumns(?string $slug): array
    {
        return self::sport($slug)['stats'] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public static function statKeys(?string $slug): array
    {
        return array_column(self::statColumns($slug), 'key');
    }

    /** The stat the leaderboard ranks by. */
    public static function primaryStatKey(?string $slug): ?string
    {
        return self::statColumns($slug)[0]['key'] ?? null;
    }

    /** The stat key carrying a role ('goal' / 'assist'), if the sport tracks it. */
    public static function statKeyForRole(?string $slug, string $role): ?string
    {
        foreach (self::statColumns($slug) as $stat) {
            if ($stat['role'] === $role) {
                return $stat['key'];
            }
        }

        return null;
    }

    /** stat_key => disciplinary weight, for the fair-play tiebreaker. */
    public static function fairPlayWeights(?string $slug): array
    {
        $out = [];
        foreach (self::statColumns($slug) as $stat) {
            if ((int) $stat['fair_play_weight'] > 0) {
                $out[$stat['key']] = (int) $stat['fair_play_weight'];
            }
        }

        return $out;
    }

    public static function isSetBased(?string $slug): bool
    {
        return (self::sport($slug)['scoring'] ?? 'goal') === 'set';
    }

    /**
     * @return array<int, string>
     */
    public static function sportSlugs(): array
    {
        return self::sports()->pluck('slug')->all();
    }

    // ---- Reference options ----

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function options(string $group): array
    {
        return self::data()['options'][$group] ?? [];
    }

    /**
     * Valid keys of a group — what Rule::in() validates against.
     *
     * @return array<int, string>
     */
    public static function keys(string $group): array
    {
        return array_column(self::options($group), 'key');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function option(string $group, ?string $key): ?array
    {
        foreach (self::options($group) as $option) {
            if ($option['key'] === $key) {
                return $option;
            }
        }

        return null;
    }

    /**
     * The engine that runs a tournament format. A format is a *preset*: several
     * of them may share one engine ("Liga 2 Putaran" and "Liga" are both
     * `league`). Falls back to the key itself, so the built-in formats keep
     * working even if the row is missing.
     */
    public static function engineOf(?string $format): ?string
    {
        return self::option('tournament_format', $format)['meta']['engine'] ?? $format;
    }

    /**
     * A format's default bracket_config, merged into new events.
     *
     * @return array<string, mixed>
     */
    public static function formatDefaults(?string $format): array
    {
        return self::option('tournament_format', $format)['meta']['defaults'] ?? [];
    }

    /** Teams held by a knockout entry round (round_of_16 → 16). */
    public static function roundSize(?string $key): ?int
    {
        $size = self::option('knockout_round', $key)['meta']['size'] ?? null;

        return $size === null ? null : (int) $size;
    }

    /**
     * Knockout entry rounds as key => size, largest bracket last.
     *
     * @return array<string, int>
     */
    public static function roundSizes(): array
    {
        $out = [];
        foreach (self::options('knockout_round') as $option) {
            $out[$option['key']] = (int) ($option['meta']['size'] ?? 0);
        }

        return $out;
    }
}
