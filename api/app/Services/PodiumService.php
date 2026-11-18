<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\GameMatch;
use Illuminate\Support\Collection;

/**
 * Who finished first, second and third in a category.
 *
 * **Derived, never stored.** There is no `category_podiums` table and no
 * `place` column, for the reason already written for `Event::isWhiteLabel()`
 * and for suspensions in DisciplineService: a stored podium is a second fact
 * about the same thing, and the two drift the moment a result is corrected.
 * An organizer who fixes a mistyped final score would otherwise leave a
 * champion standing who lost — with nothing on screen admitting it, because
 * nothing emits an event a stored column could listen for. Recomputing costs
 * one query per category and a pass over its fixtures.
 *
 * Two shapes of competition answer the question two ways, and the engine is
 * what picks:
 *
 * - **A bracket** (`knockout_single`, `knockout_double`, `hybrid`) is decided
 *   by its last ties. First and second are the winner and loser of the final;
 *   third is the winner of the third-place tie, which is a fixture the
 *   organizer opted into (`bracket_config.third_place`). Where that tie was
 *   never scheduled the podium is **two deep**, not padded with a guess — both
 *   beaten semifinalists are third in every sense the bracket can express, and
 *   naming one of them would invent a result nobody played.
 *
 * - **A table** (`league`, `swiss`) is decided by the table. The top three rows
 *   of StandingService are the podium, read through the *same* service the
 *   Klasemen tab renders — not a second ranking here. Standings are already the
 *   one answer to "who is ahead", tiebreakers and manual adjustments included,
 *   and a second reader of that question would eventually print a champion the
 *   table above it disagrees with.
 *
 * In both shapes the podium is published only once it is **final**: a bracket
 * needs its deciding ties confirmed, a table needs every fixture played. A
 * champion announced over a tie still being played is worse than no champion
 * at all, and the gate is the same one the leaderboard and the standings use
 * (`status = 'finished' AND confirmed_at IS NOT NULL`).
 */
class PodiumService
{
    public function __construct(protected StandingService $standings) {}

    /**
     * The podium of one category, best first, or an empty array while it is
     * still being decided.
     *
     * Entries are 1-2 long for a bracket without a third-place tie, up to 3
     * elsewhere; a category with two entrants yields two. The caller renders
     * what it is given rather than assuming three — see the note above on why
     * the third place is absent rather than guessed.
     *
     * @return array<int, array{place: int, team: array<string, mixed>}>
     */
    public function forCategory(EventCategory $category): array
    {
        return match ($category->engine()) {
            'knockout_single', 'knockout_double', 'hybrid' => $this->fromBracket($category),
            'league', 'swiss' => $this->fromTable($category),
            default => [],
        };
    }

    /**
     * Every category of an event that has one, keyed for the public payload.
     *
     * Categories still in play are **left out** rather than sent with an empty
     * podium: the tab is hidden until something fills it, so a row saying
     * "belum ada juara" would only ever appear next to one that does — and the
     * question this page answers is who won, not who has not won yet.
     *
     * @return array<int, array{id: string, name: string, slug: string, podium: array<int, array<string, mixed>>}>
     */
    public function forEvent(Event $event): array
    {
        $out = [];

        foreach ($event->categories()->orderBy('name')->get() as $category) {
            $podium = $this->forCategory($category);

            if ($podium === []) {
                continue;
            }

            $out[] = [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'podium' => $podium,
            ];
        }

        return $out;
    }

    /**
     * First and second from the final, third from the third-place tie.
     *
     * The final is the last tie of the bracket lane, which each engine marks
     * its own way — `knockout_double` plays a grand final (with a reset match
     * that only sometimes exists), the other two end at their deepest round.
     * finalTie() is the single place that knows the difference, for the same
     * reason bracketDepths() exists in DisciplineService: three comparisons
     * spread across this file would be three chances to disagree.
     *
     * @return array<int, array{place: int, team: array<string, mixed>}>
     */
    protected function fromBracket(EventCategory $category): array
    {
        $matches = $this->bracketMatches($category);
        $final = $this->finalTie($category, $matches);

        // Not played, not confirmed, or drawn with no shootout — nothing to
        // announce. A final that ended level and awaits a replay is exactly the
        // case a stored podium would have got wrong.
        if ($final === null || ! $this->isOfficial($final)) {
            return [];
        }

        $winner = $this->winnerOf($final);
        if ($winner === null) {
            return [];
        }

        $loser = $final->home_team_id === $winner ? $final->away_team_id : $final->home_team_id;

        $teams = $this->teams($category);
        $out = [];

        if (isset($teams[$winner])) {
            $out[] = ['place' => 1, 'team' => $teams[$winner]];
        }

        if ($loser !== null && isset($teams[$loser])) {
            $out[] = ['place' => 2, 'team' => $teams[$loser]];
        }

        // Third is its own fixture, and its own result: a category that never
        // scheduled one stops at two rather than nominating a semifinalist.
        $third = $matches->first(fn (GameMatch $m) => $m->bracket === 'third_place');

        if ($third !== null && $this->isOfficial($third)) {
            $thirdWinner = $this->winnerOf($third);

            if ($thirdWinner !== null && isset($teams[$thirdWinner])) {
                $out[] = ['place' => 3, 'team' => $teams[$thirdWinner]];
            }
        }

        return $out;
    }

    /**
     * The top three rows of the table, once every fixture has been played.
     *
     * Read through StandingService rather than ranked here: that service is
     * already the one answer to who is ahead, tiebreakers and manual point
     * adjustments included. A row still marked `needs_decider` is level on
     * everything the config can compare and holds its place by lot, so the
     * podium waits — announcing a champion who is owed a playoff is the same
     * mistake as announcing one mid-final.
     *
     * @return array<int, array{place: int, team: array<string, mixed>}>
     */
    protected function fromTable(EventCategory $category): array
    {
        if ($this->hasUnplayedFixtures($category)) {
            return [];
        }

        $rows = array_slice($this->standings->compute($category), 0, 3);

        if ($rows === []) {
            return [];
        }

        foreach ($rows as $row) {
            if (($row['needs_decider'] ?? false) === true) {
                return [];
            }
        }

        $out = [];

        foreach ($rows as $i => $row) {
            if (! isset($row['team']['id'])) {
                continue;
            }

            $out[] = ['place' => $i + 1, 'team' => $row['team']];
        }

        return $out;
    }

    /**
     * The tie that decides first place, per engine.
     *
     * `knockout_double` ends at the grand final, whose reset match (round 2)
     * is played only when the losers' bracket winner takes the first one — so
     * the later round that *exists and was played* is the decider. The other
     * two engines end at their deepest bracket round, where the final is the
     * fixture that is not the third-place tie beside it.
     *
     * @param  Collection<int, GameMatch>  $matches
     */
    protected function finalTie(EventCategory $category, Collection $matches): ?GameMatch
    {
        if ($category->engine() === 'knockout_double') {
            $grand = $matches
                ->filter(fn (GameMatch $m) => $m->bracket === 'grand_final')
                ->sortByDesc('round');

            // The reset only counts once it has been played: it is written as a
            // fixture up front, so picking the highest round blindly would read
            // an empty placeholder as the decider and hide a podium the first
            // grand final already settled.
            return $grand->first(fn (GameMatch $m) => $this->isOfficial($m))
                ?? $grand->first();
        }

        $lane = $matches->filter(fn (GameMatch $m) => $m->bracket !== 'third_place');
        $last = (int) $lane->max('round');

        if ($last === 0) {
            return null;
        }

        // A hand-added friendly shares (stage null, round 1) with a
        // single-elimination bracket and its `order` keeps counting past the
        // last slot — the same trap guarded in DisciplineService::bracketDepths()
        // and MatchController::movableSlotError(). A final sits at order 0, so
        // anything else in the last round is not it.
        return $lane->first(fn (GameMatch $m) => $m->round === $last && (int) $m->order === 0);
    }

    /**
     * The fixtures of the bracket lane, for the engine in play.
     *
     * `hybrid` marks them `stage = 'knockout'`; the two single-lane engines
     * leave `stage` null and lean on `bracket` instead. Same test as
     * DisciplineService uses, and for the same reason: a second spelling of
     * "this is a bracket fixture" is a second chance to disagree.
     *
     * @return Collection<int, GameMatch>
     */
    protected function bracketMatches(EventCategory $category): Collection
    {
        $query = $category->matches()
            ->select(['id', 'stage', 'bracket', 'round', 'order', 'home_team_id', 'away_team_id', 'home_score', 'away_score', 'home_penalty', 'away_penalty', 'status', 'confirmed_at']);

        if ($category->engine() === 'hybrid') {
            $query->where('stage', 'knockout');
        } else {
            $query->whereNull('stage');
        }

        return $query->get();
    }

    /** Any fixture of the table still to be played keeps the podium shut. */
    protected function hasUnplayedFixtures(EventCategory $category): bool
    {
        return $category->matches()
            ->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->where('status', '!=', 'finished')->orWhereNull('confirmed_at'))
            // A bye is written finished and confirmed with no opponent, so it
            // never reaches here — but a Swiss bye row has `away_team_id` null
            // and would read as unplayed under a looser test.
            ->exists();
    }

    /**
     * The category's approved entrants, by id, in the shape every other public
     * payload prints a team in.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function teams(EventCategory $category): array
    {
        return $category->teams()
            ->where('status', 'approved')
            ->get(['id', 'name', 'logo_url'])
            ->mapWithKeys(fn ($t) => [$t->id => [
                'id' => $t->id,
                'name' => $t->name,
                'logo_url' => $t->logo_url,
            ]])
            ->all();
    }

    /**
     * A result counts once it is final — the same gate the leaderboard, the
     * standings and the discipline warnings use. A looser one here would put a
     * champion on the public page before the organizer confirmed the score
     * that made them one.
     */
    protected function isOfficial(GameMatch $match): bool
    {
        return $match->status === 'finished' && $match->confirmed_at !== null;
    }

    /**
     * Who won a tie, shootout included.
     *
     * Deliberately the same arithmetic as ScheduleService::winnerOf(), which is
     * protected and belongs to bracket propagation; this reader only asks about
     * ties already played, so it skips the walkover branch — a final nobody
     * turned up for is not a podium this page should announce on its own.
     */
    protected function winnerOf(GameMatch $match): ?string
    {
        if ($match->home_score === null || $match->away_score === null) {
            return null;
        }

        if ($match->home_score > $match->away_score) {
            return $match->home_team_id;
        }

        if ($match->away_score > $match->home_score) {
            return $match->away_team_id;
        }

        // Level after normal time: the shootout decides.
        if ($match->home_penalty !== null && $match->away_penalty !== null) {
            if ($match->home_penalty > $match->away_penalty) {
                return $match->home_team_id;
            }

            if ($match->away_penalty > $match->home_penalty) {
                return $match->away_team_id;
            }
        }

        return null;
    }
}
