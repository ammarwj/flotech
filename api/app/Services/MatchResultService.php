<?php

namespace App\Services;

use App\Exceptions\MatchResultException;
use App\Models\GameMatch;
use App\Support\MatchScoring;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
     * Validate a posted scoreline and turn it into the columns to write.
     *
     * Every refusal in here is raised as a MatchResultException rather than
     * returned as a response, which is the whole reason it can live in a
     * service: the organizer's door and the match staff's door then refuse the
     * same payload with the same words, instead of one of them growing a
     * slightly different message that only its own users ever read.
     *
     * What it deliberately does *not* decide is whether the result is
     * confirmed. That is a question about who is asking, and the two callers
     * answer it differently — see the `$confirm` argument of apply().
     *
     * @return array<string, mixed> columns for apply()
     *
     * @throws MatchResultException
     */
    public function payloadFrom(Request $request, GameMatch $match): array
    {
        // A squad tie has no scoreline of its own — it is the count of partai
        // won. Accepting one here would let a hand-typed 3-0 sit on top of
        // partai that say otherwise, and the next partai edit would overwrite it.
        if ($match->category->usesRubbers()) {
            throw new MatchResultException(
                'Skor pertandingan beregu diisi per partai.',
                ['rubbers' => ['Isi skor lewat daftar partai.']],
            );
        }

        $base = $request->validate([
            'status' => ['required', Rule::in(['scheduled', 'ongoing', 'finished', 'cancelled'])],
            'scheduled_at' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
        ]);

        $finished = $base['status'] === 'finished';

        if ($match->event->isSetBased()) {
            $data = $request->validate([
                'sets' => [$finished ? 'required' : 'nullable', 'array', 'max:7'],
                'sets.*.home' => ['required', 'integer', 'min:0', 'max:99'],
                'sets.*.away' => ['required', 'integer', 'min:0', 'max:99'],
            ]);

            $sets = $data['sets'] ?? null;
            $won = $sets ? MatchScoring::setsWon($sets) : ['home' => null, 'away' => null];

            if ($finished && empty($sets)) {
                throw new MatchResultException('Isi skor minimal satu set untuk menyelesaikan pertandingan.', [
                    'sets' => ['Skor set wajib diisi.'],
                ]);
            }

            $payload = [...$base, 'sets' => $sets, 'home_score' => $won['home'], 'away_score' => $won['away']];
        } else {
            $data = $request->validate([
                'home_score' => ['nullable', 'integer', 'min:0', 'max:999'],
                'away_score' => ['nullable', 'integer', 'min:0', 'max:999'],
            ]);

            if ($finished && ($data['home_score'] === null || $data['away_score'] === null)) {
                throw new MatchResultException('Skor kedua tim wajib diisi untuk menyelesaikan pertandingan.', [
                    'home_score' => ['Skor wajib diisi.'],
                ]);
            }

            $payload = [...$base, 'home_score' => $data['home_score'], 'away_score' => $data['away_score'], 'sets' => null];
        }

        $shootout = $request->validate([
            'home_penalty' => ['nullable', 'integer', 'min:0', 'max:99'],
            'away_penalty' => ['nullable', 'integer', 'min:0', 'max:99'],
        ]);

        $level = $payload['home_score'] !== null && $payload['home_score'] === $payload['away_score'];

        // A tie that owes a winner and ended level is settled on penalties;
        // anything else has no shootout at all.
        if ($finished && $level && $this->mustProduceWinner($match)) {
            $home = $shootout['home_penalty'] ?? null;
            $away = $shootout['away_penalty'] ?? null;

            if ($home === null || $away === null) {
                throw new MatchResultException('Skor imbang — isi hasil adu penalti untuk menentukan pemenang.', [
                    'home_penalty' => ['Skor adu penalti wajib diisi.'],
                ]);
            }

            if ($home === $away) {
                throw new MatchResultException('Adu penalti tidak boleh berakhir imbang — tentukan pemenangnya.', [
                    'home_penalty' => ['Harus ada pemenang.'],
                ]);
            }

            $payload['home_penalty'] = $home;
            $payload['away_penalty'] = $away;
        } else {
            // Decided in normal time (or a group game): drop any stale shootout.
            $payload['home_penalty'] = null;
            $payload['away_penalty'] = null;
        }

        return $payload;
    }

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

    /**
     * Whether a level result here has to be settled rather than left drawn.
     *
     * Two kinds of tie owe an answer. A knockout tie has a slot waiting on it,
     * and a decider (`stage = 'playoff'`) is played for no other reason than to
     * separate two teams the table could not — one that ends level has not done
     * the only job it was scheduled for.
     */
    public function mustProduceWinner(GameMatch $match): bool
    {
        if ($match->stage === 'group') {
            return false;
        }

        if ($match->stage === 'playoff') {
            return true;
        }

        return in_array($match->category->engine(), ['knockout_single', 'knockout_double'], true)
            || $match->stage === 'knockout';
    }
}
