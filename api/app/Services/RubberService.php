<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\MatchRubber;
use App\Support\MatchScoring;
use Illuminate\Support\Collection;

/**
 * The partai a squad-vs-squad tie is played over.
 *
 * Two jobs: give a new fixture the partai its category's template calls for,
 * and keep a partai's own scoreline in sync with its sets. Rolling that up into
 * the parent match is MatchResultService's job — the knockout propagation that
 * hangs off a tie result must not fork.
 */
class RubberService
{
    /**
     * Seed a fixture with its category's template. Called from GameMatch's
     * `created` hook because fixtures are born in ten different places
     * (ScheduleService, manual entry, bracket seeding) and none of them should
     * have to remember this.
     *
     * Idempotent: a match that already has partai is left alone, so re-running
     * it over an existing fixture never duplicates or wipes a recorded result.
     */
    public function seedFor(GameMatch $match): void
    {
        $category = $match->category;

        if (! $category?->usesRubbers() || $match->rubbers()->exists()) {
            return;
        }

        foreach (array_values($category->rubber_format) as $order => $row) {
            $match->rubbers()->create([
                'order' => $order,
                'label' => $row['label'],
                'type' => $row['type'],
            ]);
        }
    }

    /**
     * Recompute a partai's own scoreline from its sets. A partai with no sets
     * yet has no score — not 0-0, which would read as a played draw when the tie
     * is rolled up.
     */
    public function applySets(MatchRubber $rubber, ?array $sets): void
    {
        $won = $sets ? MatchScoring::setsWon($sets) : ['home' => null, 'away' => null];

        $rubber->update([
            'sets' => $sets ?: null,
            'home_score' => $won['home'],
            'away_score' => $won['away'],
            'status' => $sets ? 'finished' : 'scheduled',
        ]);
    }

    /**
     * The tie's own scoreline: partai won by each side, plus the aggregate set
     * points that break a tie in the standings.
     *
     * @param  Collection<int, MatchRubber>  $rubbers
     * @return array{home: ?int, away: ?int, points: array{home: int, away: int}}
     */
    public function tally(Collection $rubbers): array
    {
        $played = $rubbers->filter(fn (MatchRubber $r) => $r->isPlayed());

        // Nothing played yet is "no result", the same null the goal-based path
        // writes for an unplayed fixture — never 0-0.
        if ($played->isEmpty()) {
            return ['home' => null, 'away' => null, 'points' => ['home' => 0, 'away' => 0]];
        }

        $won = MatchScoring::rubbersWon($played);

        return [...$won, 'points' => MatchScoring::rubberPoints($played)];
    }
}
