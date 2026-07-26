<?php

namespace App\Console\Commands;

use App\Models\EventCategory;
use App\Models\GameMatch;
use Illuminate\Console\Command;

/**
 * Stamp the stage onto hybrid group fixtures that were entered by hand.
 *
 * The manual-fixture dialog used to ask which phase a match belonged to, and it
 * defaulted to "extra match, outside the group". Whole group stages were typed
 * in through that default, so their rows carry `stage = null` — and the two
 * readers of that column disagree about what it means:
 *
 *  - StandingService::countingMatches() accepts `stage IS NULL OR 'group'`, so
 *    the tables were right all along and the group stage looked finished.
 *  - The bracket gate (MatchController::generateKnockout(),
 *    KnockoutPlanService::build()) counts `stage = 'group'` only, so it saw *no
 *    group schedule at all* and refused to build the bracket — with no way for
 *    the organizer to tell why, since every table on screen said otherwise.
 *
 * storeManual() now derives the group from the pair (see its sharedGroup()), so
 * new fixtures cannot land this way. This is for the ones already stored.
 *
 * Deliberately as strict as that derivation, with no extra guesswork: both teams
 * must sit in the same non-null group. A fixture across two groups belongs to
 * neither table and keeps its null stage. `round` is left exactly as it is —
 * it is presentation only (the matchday heading), and renumbering would split or
 * merge the headings of a category whose schedule was part generated, part typed.
 *
 * Idempotent: a stamped row no longer matches `stage IS NULL`, so re-running is
 * a no-op. Safe to run on a live database.
 */
class BackfillGroupStage extends Command
{
    protected $signature = 'matches:backfill-group-stage
        {--category= : Batasi ke satu id kategori}
        {--dry-run : Tampilkan perubahan tanpa menyimpan}';

    protected $description = 'Tandai laga grup hybrid yang dibuat manual (stage null) sebagai stage=group beserta nama grupnya.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('category');
        $stamped = 0;
        $skipped = 0;

        $scope = fn () => EventCategory::where('tournament_format', 'hybrid')
            ->when($only !== null, fn ($q) => $q->whereKey($only))
            ->with('event');

        if ($only !== null && ! $scope()->exists()) {
            $this->error("Kategori {$only} tidak ada atau bukan format hybrid.");

            return self::FAILURE;
        }

        $scope()->chunkById(50, function ($chunk) use ($dry, &$stamped, &$skipped) {
            foreach ($chunk as $category) {
                // group_name lives on teams, and a category's field is small
                // enough to read whole — one query per category instead of two
                // joins per fixture.
                $groupOf = $category->teams()->pluck('group_name', 'id');

                $matches = $category->matches()
                    ->whereNull('stage')
                    ->whereNotNull('home_team_id')
                    ->whereNotNull('away_team_id')
                    // A cancelled fixture is left alone. The gate reads anything
                    // not `finished` as pending, so stamping one would block the
                    // bracket for good — the same invisible blocker this command
                    // exists to clear. Cancelled rows count toward no table
                    // either way (countingMatches() wants `finished`).
                    ->where('status', '!=', 'cancelled')
                    ->get();

                $perCategory = 0;

                foreach ($matches as $match) {
                    $group = $groupOf[$match->home_team_id] ?? null;

                    if ($group === null || $group !== ($groupOf[$match->away_team_id] ?? null)) {
                        $skipped++;

                        continue;
                    }

                    if (! $dry) {
                        // Silent on purpose: GameMatch::booted() seeds rubbers on
                        // create, not on update, and nothing here should look
                        // like a new fixture to any other listener either.
                        GameMatch::withoutEvents(fn () => $match->update([
                            'stage' => 'group',
                            'group_name' => $group,
                        ]));
                    }

                    $stamped++;
                    $perCategory++;
                }

                if ($perCategory > 0) {
                    $this->line(sprintf(
                        '%s / %s: %d laga → stage=group',
                        $category->event?->name ?? '(event terhapus)',
                        $category->name,
                        $perCategory,
                    ));
                }
            }
        });

        $this->info($dry
            ? "{$stamped} laga akan ditandai (dry run, tidak ada yang disimpan)."
            : "{$stamped} laga ditandai sebagai laga grup.");

        if ($skipped > 0) {
            // Not a failure: an inter-group fixture is genuinely an extra match,
            // and saying so keeps the number from reading as a partial run.
            $this->line("{$skipped} laga dilewati — kedua timnya tidak satu grup.");
        }

        return self::SUCCESS;
    }
}
