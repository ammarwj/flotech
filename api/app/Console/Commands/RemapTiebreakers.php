<?php

namespace App\Console\Commands;

use App\Models\EventCategory;
use App\Services\Catalog;
use Illuminate\Console\Command;

/**
 * One-off: translate tiebreakers saved before they were sport-aware.
 *
 * Every event used to be offered the football vocabulary, so a badminton
 * category that had its config saved is holding keys like `goal_difference`.
 * Those no longer apply to its standings context, and HybridConfig::fromArray()
 * drops what does not apply — leaving that category ranking on head-to-head and
 * a coin toss, silently, the first time two entrants finish level.
 *
 * The keys mean the same thing in the new vocabulary, only under the word the
 * sport uses, so they are translated rather than discarded:
 * "Selisih Gol" → "Selisih Game", "Gol Memasukkan" → "Game Menang".
 *
 * Idempotent: a category already holding valid keys is left untouched, so it is
 * safe to run repeatedly. Goal-based categories are never eligible.
 */
class RemapTiebreakers extends Command
{
    protected $signature = 'standings:remap-tiebreakers {--dry-run : Tampilkan perubahan tanpa menyimpan}';

    protected $description = 'Terjemahkan tie breaker tersimpan ke padanan cabang olahraganya.';

    /**
     * Old key => its equivalent per standings context. A key absent from a
     * context has no equivalent there and is dropped: fair play needs cards,
     * which a racket sport does not have.
     *
     * @var array<string, array<string, string>>
     */
    private const EQUIVALENTS = [
        'goal_difference' => ['set' => 'game_difference', 'rubber' => 'rubber_difference'],
        'goals_scored' => ['set' => 'games_won'],
        'fair_play' => [],
    ];

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
                    $stored = is_array($config['tiebreakers'] ?? null) ? $config['tiebreakers'] : null;

                    if ($context === 'goal' || $stored === null) {
                        continue;
                    }

                    $remapped = $this->remap($stored, $context);

                    if ($remapped === $stored) {
                        continue;
                    }

                    $this->line(sprintf(
                        '%s / %s (%s): %s → %s',
                        $category->event->name,
                        $category->name,
                        $context,
                        implode(', ', $stored),
                        implode(', ', $remapped),
                    ));

                    if (! $dry) {
                        $category->update(['bracket_config' => [...$config, 'tiebreakers' => $remapped]]);
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
     * @param  array<int, string>  $stored
     * @return array<int, string>
     */
    private function remap(array $stored, string $context): array
    {
        $valid = Catalog::tiebreakerKeys($context);
        $out = [];

        foreach ($stored as $key) {
            // Already speaks this context's vocabulary.
            if (in_array($key, $valid, true)) {
                $out[] = $key;

                continue;
            }

            $equivalent = self::EQUIVALENTS[$key][$context] ?? null;

            // Keep the original priority, and never list one key twice — two
            // old keys can share an equivalent.
            if ($equivalent !== null && in_array($equivalent, $valid, true) && ! in_array($equivalent, $out, true)) {
                $out[] = $equivalent;
            }
        }

        return array_values($out);
    }
}
