<?php

namespace App\Services;

use App\Models\EventCategory;
use App\Models\GameMatch;
use App\Support\BracketSeeding;
use Illuminate\Support\Collection;

/**
 * What to call a fixture before its teams exist.
 *
 * A bracket slot waiting on an earlier result has nothing to print: both sides
 * read "TBD", so the schedule says "TBD vs TBD" and the draw says it twice. An
 * organizer writing the match-day programme cannot refer to that fixture at
 * all, cannot tell two of them apart, and cannot say which tie feeds it —
 * which is the one thing the wall chart they drew by hand always said.
 *
 * Two answers, from the same pass:
 *
 * - `number` — the fixture's running number inside its category, "P12".
 * - `source` per side — "Pemenang P5" where a feeder is known, the team's own
 *   name once it has arrived, null where nothing can be said.
 *
 * > **Both are derived, never stored.** The number is a function of
 * > (stage, round, order) — the same order every reader already lists fixtures
 * > in — so a column would be a second writer that drifts the moment a bracket
 * > is regenerated or a friendly is added, and drifts silently, because nothing
 * > downstream would notice a number that no longer matches the position.
 *
 * > **And the source label is computed here, not in the client.** Five surfaces
 * > print it (the draw, the organizer's schedule card, the public card, the
 * > detail dialog, the match-report PDF), and handing them raw numbers to pair
 * > up with feeders themselves is the `effective_payment_method` bug again: the
 * > positional edges are ScheduleService's to know, and a second copy of them
 * > would disagree about exactly the ragged brackets this was built for.
 */
class MatchNumberService
{
    /** The attribute MatchResource reads the answer off. */
    public const ATTRIBUTE = 'number_info';

    /**
     * Stamp every fixture in a list with its own number and slot sources.
     *
     * The list is stamped rather than each row asked, because a number is a
     * property of the *list*: asking per row would be one sweep of the category
     * per fixture, and 158 of them is the silent N+1 that fixtureRelations()
     * exists to prevent elsewhere.
     *
     * Two callers cover everything: the schedule readers, which stamp the list
     * they just built, and GameMatch::loadFixtureRelations(), which stamps the
     * single fixture every write-then-answer door returns. A door that does
     * neither simply reports null — the client prints "TBD" as it always did —
     * so forgetting this is a missing caption, never a wrong one.
     *
     * @param  Collection<int, GameMatch>  $matches
     * @return Collection<int, GameMatch> the same collection
     */
    public function attach(EventCategory $category, Collection $matches): Collection
    {
        $info = $this->forCategory($category, $matches);

        foreach ($matches as $match) {
            $match->setAttribute(self::ATTRIBUTE, $info[$match->id] ?? null);
        }

        return $matches;
    }

    /**
     * Stamp one fixture, by sweeping the category it belongs to.
     *
     * The one-row door: a number means nothing without the list it counts in,
     * so there is a query here and it is unavoidable. Only the columns the
     * numbering reads are selected — no teams, no relations — so the sweep
     * stays one cheap query however large the draw is.
     */
    public function attachOne(GameMatch $match): GameMatch
    {
        $category = $match->category;

        if (! $category) {
            return $match;
        }

        $siblings = $category->matches()
            ->select(['id', 'category_id', 'stage', 'round', 'order', 'bracket', 'home_team_id', 'away_team_id', 'status'])
            ->get();

        $info = $this->forCategory($category, $siblings);
        $match->setAttribute(self::ATTRIBUTE, $info[$match->id] ?? null);

        return $match;
    }

    /**
     * Numbers and slot sources for one category's fixtures, keyed by match id.
     *
     * Takes the fixtures rather than querying for them: every caller has
     * already loaded the list it is about to render, and a second query would
     * be a second ordering to keep in step with the first.
     *
     * @param  Collection<int, GameMatch>  $matches
     * @return array<string, array{number: int, home_source: ?string, away_source: ?string}>
     */
    public function forCategory(EventCategory $category, Collection $matches): array
    {
        // The order the number means: the same one orderedMatches() lists in,
        // so "P12" is the twelfth row of every screen that shows the list.
        $ordered = $matches
            ->sortBy([
                fn (GameMatch $a, GameMatch $b) => ($a->stage ?? '') <=> ($b->stage ?? ''),
                fn (GameMatch $a, GameMatch $b) => $a->round <=> $b->round,
                fn (GameMatch $a, GameMatch $b) => $a->order <=> $b->order,
            ])
            ->values();

        // A bye takes no number. It is not a fixture — nobody plays it, no
        // screen lists it, and the bracket does not draw it — so counting it
        // would leave the numbers that *are* printed with gaps in them: a draw
        // of 36 would run P1..P4 and then jump to P33, and an organizer writing
        // "P33" on a programme would be naming the fifth tie by its
        // thirty-third position. The number has to count what people can see.
        $numbers = [];
        $n = 0;
        foreach ($ordered as $match) {
            if ($this->isBye($match)) {
                continue;
            }
            $numbers[$match->id] = ++$n;
        }

        // Which fixture feeds which slot. Byes are deliberately not sources:
        // "Pemenang P3" for a tie nobody played tells a reader to look up a
        // fixture that will never have a result — the team that walked over is
        // already seated in the slot, and its own name is the better answer.
        $feeders = $this->feeders($category, $ordered);

        $out = [];

        foreach ($ordered as $match) {
            $out[$match->id] = [
                // Null for a bye, which is unnumbered above. Clients already
                // read a null number as "nothing to print" — that is what a
                // door which never attached this gives them.
                'number' => $numbers[$match->id] ?? null,
                'home_source' => $this->sourceLabel($match, 'home', $feeders, $numbers),
                'away_source' => $this->sourceLabel($match, 'away', $feeders, $numbers),
            ];
        }

        return $out;
    }

    /**
     * The fixture feeding each slot, keyed "{match id}:{home|away}".
     *
     * Walks the same positional edges as ScheduleService::advanceWinner() —
     * child order 2k and 2k+1 feed parent slot k — because a label that
     * disagrees with where the winner actually lands is worse than no label.
     *
     * The third-place tie is fed by the *losers* of the round below, so it is
     * answered separately and in the other direction.
     *
     * A null `match` is the third answer: nothing feeds this side and nothing
     * ever will, which is what a bye looks like before it has been written.
     *
     * @param  Collection<int, GameMatch>  $ordered
     * @return array<string, array{match: ?GameMatch, won: bool}>
     */
    private function feeders(EventCategory $category, Collection $ordered): array
    {
        $engine = $category->engine();

        // Only the single-lane brackets have edges this simple. Double
        // elimination routes losers sideways between two lanes, and naming a
        // feeder wrongly there is worse than saying nothing.
        $stage = match ($engine) {
            'knockout_single' => null,
            'hybrid' => 'knockout',
            default => false,
        };

        if ($stage === false) {
            return [];
        }

        $bracket = $ordered
            ->filter(fn (GameMatch $m) => $m->stage === $stage && $m->bracket !== 'third_place')
            ->values();

        if ($bracket->isEmpty()) {
            return [];
        }

        // A single-elimination bracket shares (stage null, round 1) with the
        // hand-added fixtures of storeManual(), whose order keeps counting past
        // the draw. Those are not slots, and treating one as a feeder would
        // caption a semifinal with a friendly.
        $slots = $stage === null
            ? $this->firstRoundWidth($category, $bracket)
            : null;

        $perRound = $slots === null ? null : BracketSeeding::roundSlotsFromFirst($slots);

        $out = [];

        foreach ($bracket as $child) {
            if ($perRound !== null) {
                $inRound = $perRound[$child->round - 1] ?? 0;
                if ($child->order >= $inRound) {
                    continue; // a friendly riding along on round 1
                }
            }

            // A bye was never played, so there is no "winner of" it to point at.
            if ($this->isBye($child)) {
                continue;
            }

            $parent = $bracket->first(
                fn (GameMatch $m) => $m->round === $child->round + 1
                    && $m->order === intdiv($child->order, 2),
            );

            if (! $parent) {
                continue;
            }

            $side = $child->order % 2 === 0 ? 'home' : 'away';
            $out[$parent->id.':'.$side] = ['match' => $child, 'won' => true];

            // A ragged round is short of a pair by exactly one, so its last tie
            // feeds a slot whose other side nothing can ever reach. That is a
            // bye, and it is drawn as one on every wall chart — printing "TBD"
            // there promises an opponent that will not arrive.
            if ($perRound !== null && $child->order % 2 === 0 && $child->order + 1 >= ($perRound[$child->round - 1] ?? 0)) {
                $out[$parent->id.':away'] = ['match' => null, 'won' => true];
            }
        }

        // The beaten semifinalists meet under the final. Its feeders are the
        // round below it, and what it wants from them is the loser.
        $third = $ordered->first(
            fn (GameMatch $m) => $m->stage === $stage && $m->bracket === 'third_place',
        );

        if ($third) {
            foreach ($bracket as $semi) {
                if ($semi->round !== $third->round - 1 || $this->isBye($semi)) {
                    continue;
                }
                if ($semi->order > 1) {
                    continue; // only the two ties that feed the final
                }

                $side = $semi->order % 2 === 0 ? 'home' : 'away';
                $out[$third->id.':'.$side] = ['match' => $semi, 'won' => false];
            }
        }

        return $out;
    }

    /**
     * How many ties the opening round holds, straight from what generation
     * recorded — with the old power-of-two height as the fallback, which is
     * exactly right for every bracket drawn before that column existed.
     *
     * @param  Collection<int, GameMatch>  $bracket
     */
    private function firstRoundWidth(EventCategory $category, Collection $bracket): int
    {
        if ($category->bracket_slots !== null) {
            return max(1, (int) $category->bracket_slots);
        }

        $maxRound = (int) $bracket->max('round');

        return intdiv(2 ** max(1, $maxRound), 2);
    }

    /** Written final with one side seated and no score — see bracket.ts isBye(). */
    private function isBye(GameMatch $match): bool
    {
        return ($match->home_team_id === null) !== ($match->away_team_id === null)
            && $match->status === 'finished';
    }

    /**
     * What to print on one side of a fixture.
     *
     * The team's own name wins whenever there is one: a slot that has been
     * filled is not waiting on anything, and captioning it "Pemenang P5" would
     * hide the answer behind the question. Null means nothing can be said — a
     * league fixture, a bye's empty side, an unseeded manual slot — and a null
     * is what lets each surface keep its own word for that ("TBD", "Bye").
     *
     * @param  array<string, array{match: ?GameMatch, won: bool}>  $feeders
     * @param  array<string, int>  $numbers
     */
    private function sourceLabel(GameMatch $match, string $side, array $feeders, array $numbers): ?string
    {
        if ($match->{$side.'_team_id'} !== null) {
            return null;
        }

        $feeder = $feeders[$match->id.':'.$side] ?? null;
        if (! $feeder) {
            return null;
        }

        // Nothing feeds this side and nothing will: the tie above is a bye.
        if ($feeder['match'] === null) {
            return 'Bye';
        }

        $no = $numbers[$feeder['match']->id] ?? null;
        if ($no === null) {
            return null;
        }

        return ($feeder['won'] ? 'Pemenang P' : 'Kalah P').$no;
    }
}
