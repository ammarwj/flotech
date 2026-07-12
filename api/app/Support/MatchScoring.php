<?php

namespace App\Support;

use App\Services\Catalog;

/**
 * How a sport is scored. Goal-based sports use a single final score; set-based
 * sports (racket sports, volleyball) are scored per set and the match score is
 * the number of sets won.
 *
 * Which sport is which comes from the catalog (`sports.scoring`), so a sport
 * declares its scoring style when the admin creates it.
 */
class MatchScoring
{
    public static function isSetBased(?string $sport): bool
    {
        return Catalog::isSetBased($sport);
    }

    /**
     * Count of sets won by each side.
     *
     * @param  array<int, array{home: int, away: int}>  $sets
     * @return array{home: int, away: int}
     */
    public static function setsWon(array $sets): array
    {
        $home = 0;
        $away = 0;

        foreach ($sets as $set) {
            if ($set['home'] > $set['away']) {
                $home++;
            } elseif ($set['away'] > $set['home']) {
                $away++;
            }
        }

        return ['home' => $home, 'away' => $away];
    }
}
