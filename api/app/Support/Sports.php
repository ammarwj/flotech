<?php

namespace App\Support;

/**
 * The sports an event can be held in. Football, mini soccer and futsal share
 * the same rules surface (goal-based, same player stats) and differ only in
 * squad size and match length.
 */
class Sports
{
    public const ALL = ['football', 'mini_soccer', 'futsal', 'badminton', 'padel', 'volleyball'];

    /** Sports played as football with a smaller pitch/squad. */
    public const FOOTBALL_FAMILY = ['football', 'mini_soccer', 'futsal'];

    public static function isFootballFamily(string $sport): bool
    {
        return in_array($sport, self::FOOTBALL_FAMILY, true);
    }
}
