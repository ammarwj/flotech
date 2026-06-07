<?php

namespace App\Support;

/**
 * How a sport is scored. Goal-based sports use a single final score; set-based
 * sports (racket sports, volleyball) are scored per set and the match score is
 * the number of sets won.
 */
class MatchScoring
{
    /** Sports scored by sets rather than a single running total. */
    public const SET_BASED = ['volleyball', 'badminton', 'padel'];

    public static function isSetBased(string $sport): bool
    {
        return in_array($sport, self::SET_BASED, true);
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
