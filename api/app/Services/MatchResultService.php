<?php

namespace App\Services;

use App\Models\GameMatch;

/**
 * Writing a result and everything that follows from it.
 *
 * A knockout result does not end at its own row: the winner is seated in the
 * next bracket slot, and changing a result that was already final has to pull
 * that winner back out first. Two doors now finish a match — a single scoreline
 * (updateResult) and a tie rolled up from its partai (RubberController) — and if
 * they each carried their own copy of that dance one of them would drift and
 * strand a team in a round it never reached. So the dance lives here, once.
 */
class MatchResultService
{
    public function __construct(protected ScheduleService $schedule) {}

    /**
     * Save a scoreline and settle the bracket around it.
     *
     * @param  array<string, mixed>  $payload  columns to write; `confirmed_at` is set here
     */
    public function apply(GameMatch $match, array $payload, bool $confirm): GameMatch
    {
        // Whatever this result had already pushed into the next round has to
        // come back out before the new one goes in — otherwise editing a
        // confirmed knockout result leaves the previous winner stranded there.
        if ($match->isConfirmed()) {
            $this->withdraw($match);
        }

        $match->update([...$payload, 'confirmed_at' => $confirm ? now() : null]);

        if ($confirm) {
            $this->propagate($match->fresh());
        }

        return $match->fresh();
    }

    /**
     * Send a settled result onward: the winner into the next bracket slot.
     *
     * Every path that makes a result final goes through here, so confirming by
     * hand and confirming by saving as an admin can't propagate differently.
     */
    public function propagate(GameMatch $match): void
    {
        $engine = $match->category->engine();

        if ($engine === 'knockout_single' || $match->stage === 'knockout') {
            $this->schedule->advanceWinner($match);
            // A semifinal also sends its loser sideways, when the category
            // plays for third place.
            $this->schedule->advanceLoser($match);
        } elseif ($engine === 'knockout_double') {
            $this->schedule->advanceDouble($match);
        }
    }

    /**
     * The inverse: pull back whatever this result had already sent onward.
     *
     * @return int matches reset
     */
    public function withdraw(GameMatch $match): int
    {
        $engine = $match->category->engine();

        if ($engine === 'knockout_single' || $engine === 'knockout_double' || $match->stage === 'knockout') {
            return $this->schedule->clearDownstream($match);
        }

        return 0;
    }

    /** Whether a level result here has to be settled rather than left drawn. */
    public function isKnockoutTie(GameMatch $match): bool
    {
        if ($match->stage === 'group') {
            return false;
        }

        return in_array($match->category->engine(), ['knockout_single', 'knockout_double'], true)
            || $match->stage === 'knockout';
    }
}
