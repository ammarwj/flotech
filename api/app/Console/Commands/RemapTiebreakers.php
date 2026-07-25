<?php

namespace App\Console\Commands;

use App\Models\EventCategory;
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
 * Idempotent: a category already holding valid values is left untouched, so it
 * is safe to run repeatedly. Goal-based categories are never eligible.
 */
class RemapTiebreakers extends Command
{
    protected $signature = 'standings:remap-tiebreakers {--dry-run : Tampilkan perubahan tanpa menyimpan}';

    protected $description = 'Terjemahkan tie breaker tersimpan ke padanan cabang olahraganya.';

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

                    // Football's vocabulary was never wrong for a goal sport,
                    // and neither were its points.
                    if ($context === 'goal') {
                        continue;
                    }

                    $next = $config;

                    if (is_array($config['tiebreakers'] ?? null)) {
                        $next['tiebreakers'] = Tiebreakers::remap($config['tiebreakers'], $context);
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
