<?php

namespace App\Support;

/**
 * What the code can actually run.
 *
 * Formats, tiebreakers and draw methods are configurable *data* (label, order,
 * on/off, presets), but each one still has to point at an algorithm that
 * exists. This class is the single list of those algorithms; a config_options
 * row whose meta names something outside it is rejected at validation.
 *
 * Adding a genuinely new engine means writing code — and adding it here.
 */
class Engines
{
    /** Scheduling/standings engines, keyed to the branches in MatchController. */
    public const FORMATS = ['league', 'knockout_single', 'knockout_double', 'hybrid'];

    /** Comparators implemented by StandingService::compareBy(). */
    public const TIEBREAKERS = [
        'head_to_head',
        'goal_difference',
        'goals_scored',
        'fair_play',
        'drawing_lots',
    ];

    /** Strategies implemented by GroupDrawService. */
    public const DRAW_METHODS = ['random', 'manual', 'pot'];

    /**
     * Everything the admin UI needs to fill its "which engine?" dropdowns.
     *
     * @return array<string, array<int, string>>
     */
    public static function all(): array
    {
        return [
            'formats' => self::FORMATS,
            'tiebreakers' => self::TIEBREAKERS,
            'draw_methods' => self::DRAW_METHODS,
        ];
    }
}
