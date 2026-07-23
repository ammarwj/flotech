<?php

namespace Database\Seeders;

use App\Models\Sport;
use App\Models\SportPosition;
use App\Models\SportStat;
use App\Services\Catalog;
use Illuminate\Database\Seeder;

/**
 * The sports the platform shipped with, now as data. Idempotent — safe to
 * re-run; an admin's edits to other sports are untouched.
 */
class SportSeeder extends Seeder
{
    public function run(): void
    {
        $goalStats = [
            ['stat_key' => 'goals', 'label' => 'Gol', 'short' => 'G', 'role' => 'goal'],
            ['stat_key' => 'assists', 'label' => 'Assist', 'short' => 'A', 'role' => 'assist'],
            ['stat_key' => 'yellow_cards', 'label' => 'Kartu Kuning', 'short' => 'KK', 'fair_play_weight' => 1],
            ['stat_key' => 'red_cards', 'label' => 'Kartu Merah', 'short' => 'KM', 'fair_play_weight' => 3],
        ];

        // The key is what a roster stores; the label is only what it's shown as.
        $goalPositions = [
            ['position_key' => 'goalkeeper', 'label' => 'Kiper'],
            ['position_key' => 'defender', 'label' => 'Bek'],
            ['position_key' => 'midfielder', 'label' => 'Gelandang'],
            ['position_key' => 'winger', 'label' => 'Sayap'],
            ['position_key' => 'forward', 'label' => 'Penyerang'],
        ];

        // Racket sports are entered three ways: one player, a pair, or a squad
        // whose ties are played over several partai. Everything else fields a
        // squad and nothing else.
        $racketModes = ['single', 'double', 'team'];
        $racketStats = [
            ['stat_key' => 'points', 'label' => 'Poin', 'short' => 'PTS'],
            ['stat_key' => 'aces', 'label' => 'Ace', 'short' => 'ACE'],
            ['stat_key' => 'winners', 'label' => 'Winner', 'short' => 'WIN'],
        ];
        $racketPositions = [
            ['position_key' => 'singles', 'label' => 'Tunggal'],
            ['position_key' => 'doubles', 'label' => 'Ganda'],
        ];

        $sports = [
            [
                'slug' => 'football', 'name' => 'Sepak Bola', 'color' => '#1E6FFF', 'icon' => '⚽',
                'scoring' => 'goal', 'default_match_minutes' => 90, 'stats' => $goalStats,
                'positions' => [
                    ['position_key' => 'goalkeeper', 'label' => 'Kiper'],
                    ['position_key' => 'defender', 'label' => 'Bek'],
                    ['position_key' => 'wing_back', 'label' => 'Bek Sayap'],
                    ['position_key' => 'midfielder', 'label' => 'Gelandang'],
                    ['position_key' => 'attacking_midfielder', 'label' => 'Gelandang Serang'],
                    ['position_key' => 'winger', 'label' => 'Sayap'],
                    ['position_key' => 'forward', 'label' => 'Penyerang'],
                ],
            ],
            [
                'slug' => 'mini_soccer', 'name' => 'Mini Soccer', 'color' => '#0EA5E9', 'icon' => '🥅',
                'scoring' => 'goal', 'default_match_minutes' => 50, 'stats' => $goalStats,
                'positions' => $goalPositions,
            ],
            [
                'slug' => 'futsal', 'name' => 'Futsal', 'color' => '#7C3AED', 'icon' => '🏟️',
                'scoring' => 'goal', 'default_match_minutes' => 40, 'stats' => $goalStats,
                'positions' => [
                    ['position_key' => 'goalkeeper', 'label' => 'Kiper'],
                    ['position_key' => 'anchor', 'label' => 'Anchor'],
                    ['position_key' => 'flank', 'label' => 'Flank'],
                    ['position_key' => 'pivot', 'label' => 'Pivot'],
                ],
            ],
            [
                'slug' => 'badminton', 'name' => 'Badminton', 'color' => '#DB2777', 'icon' => '🏸',
                'scoring' => 'set', 'participant_modes' => $racketModes, 'default_match_minutes' => 30,
                'stats' => [
                    ['stat_key' => 'points', 'label' => 'Poin', 'short' => 'PTS'],
                    ['stat_key' => 'aces', 'label' => 'Ace', 'short' => 'ACE'],
                ],
                'positions' => $racketPositions,
            ],
            [
                'slug' => 'tennis', 'name' => 'Tenis', 'color' => '#65A30D', 'icon' => '🎾',
                'scoring' => 'set', 'participant_modes' => $racketModes, 'default_match_minutes' => 90,
                'stats' => $racketStats,
                'positions' => $racketPositions,
            ],
            [
                'slug' => 'table_tennis', 'name' => 'Tenis Meja', 'color' => '#0891B2', 'icon' => '🏓',
                'scoring' => 'set', 'participant_modes' => $racketModes, 'default_match_minutes' => 30,
                'stats' => $racketStats,
                'positions' => $racketPositions,
            ],
            [
                'slug' => 'padel', 'name' => 'Padel', 'color' => '#D97706', 'icon' => '🥎',
                'scoring' => 'set', 'participant_modes' => $racketModes, 'default_match_minutes' => 40,
                'stats' => $racketStats,
                'positions' => [
                    ['position_key' => 'drive', 'label' => 'Drive'],
                    ['position_key' => 'reves', 'label' => 'Reves'],
                ],
            ],
            [
                'slug' => 'volleyball', 'name' => 'Voli', 'color' => '#059669', 'icon' => '🏐',
                'scoring' => 'set', 'default_match_minutes' => 60,
                'stats' => [
                    ['stat_key' => 'points', 'label' => 'Poin', 'short' => 'PTS'],
                    ['stat_key' => 'aces', 'label' => 'Ace', 'short' => 'ACE'],
                    ['stat_key' => 'blocks', 'label' => 'Blok', 'short' => 'BLK'],
                ],
                'positions' => [
                    ['position_key' => 'setter', 'label' => 'Setter'],
                    ['position_key' => 'outside_hitter', 'label' => 'Outside Hitter'],
                    ['position_key' => 'middle_blocker', 'label' => 'Middle Blocker'],
                    ['position_key' => 'opposite', 'label' => 'Opposite'],
                    ['position_key' => 'libero', 'label' => 'Libero'],
                ],
            ],
            [
                // One running score per team (98-92), so 'goal' scoring — same shape
                // as football, not sets. The leaderboard ranks by the 'goal'-role
                // stat, which here is Poin: the points leader is the top scorer.
                'slug' => 'basketball', 'name' => 'Basket', 'color' => '#EA580C', 'icon' => '🏀',
                'scoring' => 'goal', 'default_match_minutes' => 40,
                'stats' => [
                    ['stat_key' => 'points', 'label' => 'Poin', 'short' => 'PTS', 'role' => 'goal'],
                    ['stat_key' => 'assists', 'label' => 'Assist', 'short' => 'AST', 'role' => 'assist'],
                    ['stat_key' => 'rebounds', 'label' => 'Rebound', 'short' => 'REB'],
                    ['stat_key' => 'steals', 'label' => 'Steal', 'short' => 'STL'],
                    ['stat_key' => 'blocks', 'label' => 'Blok', 'short' => 'BLK'],
                ],
                'positions' => [
                    ['position_key' => 'point_guard', 'label' => 'Point Guard'],
                    ['position_key' => 'shooting_guard', 'label' => 'Shooting Guard'],
                    ['position_key' => 'small_forward', 'label' => 'Small Forward'],
                    ['position_key' => 'power_forward', 'label' => 'Power Forward'],
                    ['position_key' => 'center', 'label' => 'Center'],
                ],
            ],
        ];

        foreach ($sports as $order => $data) {
            $stats = $data['stats'];
            $positions = $data['positions'];
            unset($data['stats'], $data['positions']);

            $sport = Sport::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    ...$data,
                    'participant_modes' => $data['participant_modes'] ?? ['team'],
                    'is_active' => true,
                    'sort_order' => $order,
                ],
            );

            foreach ($stats as $statOrder => $stat) {
                SportStat::updateOrCreate(
                    ['sport_id' => $sport->id, 'stat_key' => $stat['stat_key']],
                    [
                        'label' => $stat['label'],
                        'short' => $stat['short'],
                        'role' => $stat['role'] ?? null,
                        'fair_play_weight' => $stat['fair_play_weight'] ?? 0,
                        'sort_order' => $statOrder,
                    ],
                );
            }

            foreach ($positions as $posOrder => $position) {
                SportPosition::updateOrCreate(
                    ['sport_id' => $sport->id, 'position_key' => $position['position_key']],
                    ['label' => $position['label'], 'sort_order' => $posOrder],
                );
            }
        }

        Catalog::flush();
    }
}
