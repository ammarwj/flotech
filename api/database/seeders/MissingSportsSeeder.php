<?php

namespace Database\Seeders;

use App\Models\Sport;
use App\Models\SportPosition;
use App\Models\SportStat;
use App\Services\Catalog;
use Illuminate\Database\Seeder;

/**
 * Adds sports that shipped after prod was first seeded — Tenis, Tenis Meja, and
 * Basket — without touching anything already there.
 *
 * Why not just re-run SportSeeder? That one updateOrCreate's EVERY sport, so it
 * would reset an admin's edits to football/futsal/etc. back to seed defaults.
 * This one names only the three new slugs, leaves existing rows alone, and
 * appends them after the current max sort_order so display order isn't shuffled.
 *
 * Idempotent — safe to re-run. On the server:
 *   php artisan db:seed --class=MissingSportsSeeder
 */
class MissingSportsSeeder extends Seeder
{
    public function run(): void
    {
        // Racket sports: one player, a pair, or a squad whose ties are played
        // over several partai. Same shape SportSeeder uses.
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
                // One running score per team (98-92) → 'goal' scoring, same shape as
                // football. Leaderboard ranks by the 'goal'-role stat = Poin.
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

        // Append after whatever's already there, so existing sort_order stays put.
        $nextOrder = (int) (Sport::max('sort_order') ?? -1) + 1;

        foreach ($sports as $data) {
            $stats = $data['stats'];
            $positions = $data['positions'];
            unset($data['stats'], $data['positions']);

            $existing = Sport::where('slug', $data['slug'])->first();

            $sport = Sport::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    ...$data,
                    'participant_modes' => $data['participant_modes'] ?? ['team'],
                    'is_active' => true,
                    // Keep an existing row's position; only assign one when creating.
                    'sort_order' => $existing->sort_order ?? $nextOrder++,
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
