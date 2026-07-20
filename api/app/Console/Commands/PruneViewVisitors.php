<?php

namespace App\Console\Commands;

use App\Services\EventViewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Drop the visitor dedup ledger once its days are long past.
 *
 * A row in `event_view_visitors` only ever answers "has this visitor been
 * counted on this event today?", so it is dead weight the moment its day ends;
 * the retention window is there for auditing, not for analytics.
 *
 * The daily roll-up in `event_view_daily` is never touched. That table *is*
 * the statistics, and it is small: one row per event per day.
 */
class PruneViewVisitors extends Command
{
    protected $signature = 'views:prune {--days=90}';

    protected $description = 'Hapus ledger dedup pengunjung yang sudah lewat masa simpan (agregat hariannya tetap disimpan).';

    public function handle(EventViewService $views): int
    {
        $days = max((int) $this->option('days'), 1);
        $cutoff = $views->today()->subDays($days)->toDateString();

        // Batched so a long-neglected table can't hold a lock over the whole
        // sweep — the beacon writes to this table on every visit.
        $deleted = 0;

        do {
            $batch = DB::table('event_view_visitors')
                ->where('viewed_on', '<', $cutoff)
                ->limit(10000)
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        $this->info("{$deleted} baris ledger pengunjung sebelum {$cutoff} dihapus.");

        return self::SUCCESS;
    }
}
