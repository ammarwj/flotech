<?php

namespace App\Support;

use App\Services\Catalog;

/**
 * Translating a stored tiebreaker order into the vocabulary of the category
 * that holds it.
 *
 * A config is written once and read forever, but the standings context under it
 * can change: an event created while the sport dropdown still showed the default
 * carries football keys, and so does one whose sport was edited afterwards. The
 * keys mean the same thing in every vocabulary, only under the word the sport
 * uses — "Selisih Gol" is "Selisih Game" in badminton — so they are translated
 * rather than discarded, and the organizer keeps the priorities they chose.
 *
 * This is the one place that translation lives: HybridConfig applies it on every
 * read, EventCategoryResource sends the result to the client, and
 * `standings:remap-tiebreakers` writes it back to the rows. Three copies of the
 * map would be three chances for the table to rank on something other than what
 * the event page prints under it.
 */
class Tiebreakers
{
    /**
     * Old key => its equivalent per standings context. A key absent from a
     * context has no equivalent there and is dropped: fair play needs cards,
     * which a racket sport does not have.
     *
     * @var array<string, array<string, string>>
     */
    private const EQUIVALENTS = [
        'goal_difference' => ['set' => 'game_difference', 'rubber' => 'rubber_difference'],
        'goals_scored' => ['set' => 'games_won'],
        'fair_play' => [],
        'penalty_shootout' => ['set' => 'decider_match', 'rubber' => 'decider_match'],
    ];

    /**
     * The stored order as this context can actually compute it.
     *
     * @param  array<int, string>  $stored
     * @return array<int, string>
     */
    public static function remap(array $stored, string $context): array
    {
        $valid = Catalog::tiebreakerKeys($context);
        $out = [];

        foreach ($stored as $key) {
            // Already speaks this context's vocabulary; otherwise the word this
            // one goes by here, if it has one.
            $resolved = in_array($key, $valid, true)
                ? $key
                : (self::EQUIVALENTS[$key][$context] ?? null);

            if ($resolved === null || ! in_array($resolved, $valid, true)) {
                continue;
            }

            // Keep the original priority, and never list one key twice: two
            // stored keys can land on the same one — a config holding both
            // "Selisih Gol" and "Selisih Game" is exactly the half-translated
            // row this exists to clean up.
            if (! in_array($resolved, $out, true)) {
                $out[] = $resolved;
            }
        }

        return array_values($out);
    }
}
