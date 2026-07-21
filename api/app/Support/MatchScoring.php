<?php

namespace App\Support;

use App\Models\MatchRubber;
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

    /**
     * A tie's scoreline: how many partai each side won. "Spanyol 3-0 Argentina"
     * is this, not a run of sets.
     *
     * @param  iterable<MatchRubber>  $rubbers
     * @return array{home: int, away: int}
     */
    public static function rubbersWon(iterable $rubbers): array
    {
        $home = 0;
        $away = 0;

        foreach ($rubbers as $rubber) {
            if ($rubber->home_score > $rubber->away_score) {
                $home++;
            } elseif ($rubber->away_score > $rubber->home_score) {
                $away++;
            }
        }

        return ['home' => $home, 'away' => $away];
    }

    /**
     * Every point scored across every set of every partai. Two squads can split
     * a tie 3-3, and this is what separates them in the table.
     *
     * @param  iterable<MatchRubber>  $rubbers
     * @return array{home: int, away: int}
     */
    public static function rubberPoints(iterable $rubbers): array
    {
        $home = 0;
        $away = 0;

        foreach ($rubbers as $rubber) {
            foreach ($rubber->sets ?? [] as $set) {
                $home += (int) $set['home'];
                $away += (int) $set['away'];
            }
        }

        return ['home' => $home, 'away' => $away];
    }
}
