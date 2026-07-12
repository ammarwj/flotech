<?php

namespace Database\Seeders;

use App\Models\Sport;
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

        $sports = [
            [
                'slug' => 'football', 'name' => 'Sepak Bola', 'color' => '#1E6FFF', 'icon' => '⚽',
                'scoring' => 'goal', 'default_match_minutes' => 90, 'stats' => $goalStats,
            ],
            [
                'slug' => 'mini_soccer', 'name' => 'Mini Soccer', 'color' => '#0EA5E9', 'icon' => '🥅',
                'scoring' => 'goal', 'default_match_minutes' => 50, 'stats' => $goalStats,
            ],
            [
                'slug' => 'futsal', 'name' => 'Futsal', 'color' => '#7C3AED', 'icon' => '🏟️',
                'scoring' => 'goal', 'default_match_minutes' => 40, 'stats' => $goalStats,
            ],
            [
                'slug' => 'badminton', 'name' => 'Badminton', 'color' => '#DB2777', 'icon' => '🏸',
                'scoring' => 'set', 'default_match_minutes' => 30,
                'stats' => [
                    ['stat_key' => 'points', 'label' => 'Poin', 'short' => 'PTS'],
                    ['stat_key' => 'aces', 'label' => 'Ace', 'short' => 'ACE'],
                ],
            ],
            [
                'slug' => 'padel', 'name' => 'Padel', 'color' => '#D97706', 'icon' => '🎾',
                'scoring' => 'set', 'default_match_minutes' => 40,
                'stats' => [
                    ['stat_key' => 'points', 'label' => 'Poin', 'short' => 'PTS'],
                    ['stat_key' => 'aces', 'label' => 'Ace', 'short' => 'ACE'],
                    ['stat_key' => 'winners', 'label' => 'Winner', 'short' => 'WIN'],
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
            ],
        ];

        foreach ($sports as $order => $data) {
            $stats = $data['stats'];
            unset($data['stats']);

            $sport = Sport::updateOrCreate(
                ['slug' => $data['slug']],
                [...$data, 'is_active' => true, 'sort_order' => $order],
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
        }

        Catalog::flush();
    }
}
