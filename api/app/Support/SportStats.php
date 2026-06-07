<?php

namespace App\Support;

/**
 * Per-sport player statistic definitions. The first column is the "primary"
 * stat used to rank the leaderboard. Keeps the stats table dynamic across all
 * supported sports.
 */
class SportStats
{
    /**
     * @var array<string, array<int, array{key: string, label: string, short: string}>>
     */
    public const MAP = [
        'football' => [
            ['key' => 'goals', 'label' => 'Gol', 'short' => 'G'],
            ['key' => 'assists', 'label' => 'Assist', 'short' => 'A'],
            ['key' => 'yellow_cards', 'label' => 'Kartu Kuning', 'short' => 'KK'],
            ['key' => 'red_cards', 'label' => 'Kartu Merah', 'short' => 'KM'],
        ],
        'futsal' => [
            ['key' => 'goals', 'label' => 'Gol', 'short' => 'G'],
            ['key' => 'assists', 'label' => 'Assist', 'short' => 'A'],
            ['key' => 'yellow_cards', 'label' => 'Kartu Kuning', 'short' => 'KK'],
            ['key' => 'red_cards', 'label' => 'Kartu Merah', 'short' => 'KM'],
        ],
        'volleyball' => [
            ['key' => 'points', 'label' => 'Poin', 'short' => 'PTS'],
            ['key' => 'aces', 'label' => 'Ace', 'short' => 'ACE'],
            ['key' => 'blocks', 'label' => 'Blok', 'short' => 'BLK'],
        ],
        'badminton' => [
            ['key' => 'points', 'label' => 'Poin', 'short' => 'PTS'],
            ['key' => 'aces', 'label' => 'Ace', 'short' => 'ACE'],
        ],
        'padel' => [
            ['key' => 'points', 'label' => 'Poin', 'short' => 'PTS'],
            ['key' => 'aces', 'label' => 'Ace', 'short' => 'ACE'],
            ['key' => 'winners', 'label' => 'Winner', 'short' => 'WIN'],
        ],
    ];

    /**
     * @return array<int, array{key: string, label: string, short: string}>
     */
    public static function columns(string $sport): array
    {
        return self::MAP[$sport] ?? self::MAP['football'];
    }

    /**
     * @return array<int, string>
     */
    public static function keys(string $sport): array
    {
        return array_column(self::columns($sport), 'key');
    }

    public static function primaryKey(string $sport): string
    {
        return self::columns($sport)[0]['key'];
    }
}
