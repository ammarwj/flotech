<?php

namespace App\Console\Commands;

use App\Models\EventCategory;
use App\Services\Catalog;
use App\Support\HybridConfig;
use App\Support\Tiebreakers;
use Illuminate\Console\Command;

/**
 * Write back what the config already reads as.
 *
 * Every event used to be offered the football vocabulary, so a badminton
 * category that had its config saved is holding keys like `goal_difference`,
 * and the 3/1/0 that came with them. Nothing breaks if it keeps them —
 * HybridConfig::fromArray() translates on read and the API sends the translated
 * values to the client — but the stored row then disagrees with everything
 * shown on top of it, which is a bad thing to leave in a table people read by
 * hand.
 *
 * It also backfills tiebreakers added to the catalog after a config was saved.
 * A stored order is a closed list — HybridConfig only falls back to the full
 * catalog when it has none at all — so a new rule would otherwise reach only
 * events created after it, and every existing one would silently keep ranking
 * without it. "Adu Penalti" is the case this was written for: it belongs above
 * the lot, and a category still ending at the lot never learns it exists.
 *
 * Idempotent: a category already holding valid values is left untouched, so it
 * is safe to run repeatedly. Goal-based categories keep their vocabulary — the
 * football words were never wrong for them — but they are eligible for the
 * backfill above.
 */
class RemapTiebreakers extends Command
{
    protected $signature = 'standings:remap-tiebreakers {--dry-run : Tampilkan perubahan tanpa menyimpan}';

    protected $description = 'Terjemahkan tie breaker tersimpan ke padanan cabang olahraganya, dan sisipkan aturan baru yang belum dikenal config lama.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;

        EventCategory::whereNotNull('bracket_config')
            ->with('event')
            ->chunkById(200, function ($categories) use ($dry, &$changed) {
                foreach ($categories as $category) {
                    if (! $category->event) {
                        continue;
                    }

                    $context = $category->standingsContext();
                    $config = $category->bracket_config;
                    $next = $config;

                    if (is_array($config['tiebreakers'] ?? null)) {
                        // Translating a goal config into goal words is a no-op,
                        // so this runs for every context — what it does drop
                        // there is a key the sport cannot compute anyway, which
                        // HybridConfig is already dropping on read.
                        $next['tiebreakers'] = $this->withPlayoff(
                            Tiebreakers::remap($config['tiebreakers'], $context),
                            $context,
                        );
                    }

                    // The draw point rode in with those keys — see HybridConfig,
                    // which drops the trio on read for the same reason. Take the
                    // effective values from there rather than restating the rule.
                    if ($context === 'set' && (int) ($config['points']['draw'] ?? 0) > 0) {
                        $effective = HybridConfig::fromCategory($category);
                        $next['points'] = [
                            'win' => $effective->pointsWin,
                            'draw' => $effective->pointsDraw,
                            'lose' => $effective->pointsLose,
                        ];
                    }

                    if ($next === $config) {
                        continue;
                    }

                    $this->line(sprintf(
                        '%s / %s (%s): %s → %s',
                        $category->event->name,
                        $category->name,
                        $context,
                        $this->describe($config),
                        $this->describe($next),
                    ));

                    if (! $dry) {
                        $category->update(['bracket_config' => $next]);
                    }

                    $changed++;
                }
            });

        $this->info($dry
            ? "{$changed} kategori akan diubah (dry run, tidak ada yang disimpan)."
            : "{$changed} kategori diperbarui.");

        return self::SUCCESS;
    }

    /**
     * Seat the decider tie immediately above the lot, if this order still ends
     * there without one.
     *
     * Anchored on the lot rather than appended, because the point of the rule is
     * that it is the last thing tried *before* giving up and drawing a name. An
     * order the organizer deliberately ended somewhere else is left alone: no
     * lot, nothing to sit above.
     *
     * Which key that is comes from the catalog, not from here — a goal sport
     * calls it "Adu Penalti" and a racket sport "Laga Penentuan", and both run
     * the same comparator.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private function withPlayoff(array $keys, string $context): array
    {
        $comparators = array_map(fn ($key) => Catalog::comparatorOf($key), $keys);

        if (in_array('playoff', $comparators, true)) {
            return $keys;
        }

        $at = array_search('drawing_lots', $comparators, true);

        if ($at === false) {
            return $keys;
        }

        $playoff = null;
        foreach (Catalog::tiebreakerKeys($context) as $key) {
            if (Catalog::comparatorOf($key) === 'playoff') {
                $playoff = $key;
                break;
            }
        }

        if ($playoff === null) {
            return $keys;
        }

        array_splice($keys, $at, 0, [$playoff]);

        return $keys;
    }

    /**
     * The two parts of a config this command touches, for the before/after line.
     *
     * @param  array<string, mixed>  $config
     */
    private function describe(array $config): string
    {
        $parts = [];

        if (is_array($config['tiebreakers'] ?? null)) {
            $parts[] = implode(', ', $config['tiebreakers']);
        }

        if (is_array($config['points'] ?? null)) {
            $points = $config['points'];
            $parts[] = sprintf('poin %d/%d/%d', $points['win'] ?? 0, $points['draw'] ?? 0, $points['lose'] ?? 0);
        }

        return implode(' | ', $parts);
    }
}
