<?php

namespace Database\Seeders;

use App\Models\ConfigOption;
use App\Services\Catalog;
use Illuminate\Database\Seeder;

/**
 * The reference options that used to live in PHP constants. Every format,
 * tiebreaker and draw method names the engine that runs it (App\Support\Engines).
 */
class ConfigOptionSeeder extends Seeder
{
    public function run(): void
    {
        $options = [
            // ---- Tournament formats (meta.engine picks the algorithm) ----
            ['group' => 'tournament_format', 'key' => 'league', 'label' => 'Liga',
                'meta' => ['engine' => 'league']],
            ['group' => 'tournament_format', 'key' => 'knockout_single', 'label' => 'Knockout',
                'meta' => ['engine' => 'knockout_single']],
            ['group' => 'tournament_format', 'key' => 'knockout_double', 'label' => 'Knockout Ganda',
                'meta' => ['engine' => 'knockout_double']],
            ['group' => 'tournament_format', 'key' => 'hybrid', 'label' => 'Grup + Knockout (Hybrid)',
                'meta' => ['engine' => 'hybrid']],

            // ---- Tiebreakers (meta.comparator + meta.applies_to) ----
            //
            // A tiebreaker is a *preset* over a comparator, the same way a format
            // is a preset over an engine: "Selisih Gol" and "Selisih Game" both
            // run `goal_difference`, because a match score means gol in football
            // and game menang in badminton. That is also why each row names the
            // standings contexts it belongs in (`applies_to`, empty = all of
            // them) — an event only ever offers the rows that fit its sport.
            ['group' => 'tiebreaker', 'key' => 'head_to_head', 'label' => 'Head to Head',
                'meta' => ['comparator' => 'head_to_head']],
            ['group' => 'tiebreaker', 'key' => 'goal_difference', 'label' => 'Selisih Gol',
                'meta' => ['comparator' => 'goal_difference', 'applies_to' => ['goal']]],
            ['group' => 'tiebreaker', 'key' => 'goals_scored', 'label' => 'Gol Memasukkan',
                'meta' => ['comparator' => 'goals_scored', 'applies_to' => ['goal']]],
            // Racket singles/doubles: the match score is games won.
            ['group' => 'tiebreaker', 'key' => 'game_difference', 'label' => 'Selisih Game',
                'meta' => ['comparator' => 'goal_difference', 'applies_to' => ['set']]],
            // Squad ties: the match score is partai won, and the games sit a
            // tier below it.
            ['group' => 'tiebreaker', 'key' => 'rubber_difference', 'label' => 'Selisih Partai',
                'meta' => ['comparator' => 'goal_difference', 'applies_to' => ['rubber']]],
            ['group' => 'tiebreaker', 'key' => 'rubber_games', 'label' => 'Selisih Game',
                'meta' => ['comparator' => 'set_difference', 'applies_to' => ['rubber']]],
            // Aggregate points across every set — what separates two entrants
            // that split their games (or, for a squad, their ties).
            //
            // The key still reads `rubber_points` although it now covers plain
            // set categories too: renaming it would strand every bracket_config
            // that already stores it, since HybridConfig::fromArray() drops keys
            // the catalog no longer knows.
            ['group' => 'tiebreaker', 'key' => 'rubber_points', 'label' => 'Selisih Skor',
                'meta' => ['comparator' => 'point_difference', 'applies_to' => ['set', 'rubber']]],
            ['group' => 'tiebreaker', 'key' => 'games_won', 'label' => 'Game Menang',
                'meta' => ['comparator' => 'goals_scored', 'applies_to' => ['set']]],
            ['group' => 'tiebreaker', 'key' => 'fair_play', 'label' => 'Fair Play',
                'meta' => ['comparator' => 'fair_play', 'applies_to' => ['goal']]],
            ['group' => 'tiebreaker', 'key' => 'drawing_lots', 'label' => 'Undian',
                'meta' => ['comparator' => 'drawing_lots']],

            // ---- Group draw methods (meta.strategy) ----
            ['group' => 'draw_method', 'key' => 'random', 'label' => 'Undian Acak',
                'meta' => ['strategy' => 'random']],
            ['group' => 'draw_method', 'key' => 'manual', 'label' => 'Atur Manual',
                'meta' => ['strategy' => 'manual']],
            ['group' => 'draw_method', 'key' => 'pot', 'label' => 'Seeding Pot',
                'meta' => ['strategy' => 'pot']],

            // ---- Knockout entry rounds (meta.size = teams in the bracket) ----
            ['group' => 'knockout_round', 'key' => 'final', 'label' => 'Final', 'meta' => ['size' => 2]],
            ['group' => 'knockout_round', 'key' => 'semifinal', 'label' => 'Semifinal', 'meta' => ['size' => 4]],
            ['group' => 'knockout_round', 'key' => 'quarter_final', 'label' => 'Perempat Final', 'meta' => ['size' => 8]],
            ['group' => 'knockout_round', 'key' => 'round_of_16', 'label' => '16 Besar', 'meta' => ['size' => 16]],
            ['group' => 'knockout_round', 'key' => 'round_of_32', 'label' => '32 Besar', 'meta' => ['size' => 32]],
            ['group' => 'knockout_round', 'key' => 'round_of_64', 'label' => '64 Besar', 'meta' => ['size' => 64]],

            // ---- Sponsor tiers (most prominent first) ----
            ['group' => 'sponsor_tier', 'key' => 'host', 'label' => 'Diselenggarakan oleh'],
            ['group' => 'sponsor_tier', 'key' => 'sponsor', 'label' => 'Sponsor'],
            ['group' => 'sponsor_tier', 'key' => 'media_partner', 'label' => 'Media Partner'],
            ['group' => 'sponsor_tier', 'key' => 'supporter', 'label' => 'Didukung oleh'],
        ];

        // sort_order runs per group, in the order listed above.
        $counters = [];

        foreach ($options as $option) {
            $group = $option['group'];
            $counters[$group] = ($counters[$group] ?? -1) + 1;

            ConfigOption::updateOrCreate(
                ['group' => $group, 'key' => $option['key']],
                [
                    'label' => $option['label'],
                    'description' => $option['description'] ?? null,
                    'meta' => $option['meta'] ?? null,
                    'is_active' => true,
                    'sort_order' => $counters[$group],
                ],
            );
        }

        Catalog::flush();
    }
}
