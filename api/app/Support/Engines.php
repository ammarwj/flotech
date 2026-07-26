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

    /**
     * Comparators implemented by StandingService::compareBy().
     *
     * Several tiebreaker rows may share one of these — "Selisih Gol" and
     * "Selisih Game" are both `goal_difference`, only the word for a match
     * score differs — exactly like formats sharing an engine.
     */
    public const TIEBREAKERS = [
        'head_to_head',
        'goal_difference',
        'goals_scored',
        'set_difference',
        'point_difference',
        // The name `point_difference` used to carry before it covered plain set
        // categories as well. Kept so rows seeded earlier still validate.
        'rubber_points',
        'fair_play',
        // The extra tie played to separate two entrants nothing else could —
        // football settles it on penalties, a racket sport just replays it.
        'playoff',
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
