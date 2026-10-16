<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Drop Midtrans notification rows once they are long past being useful.
 *
 * `payment_notifications` only ever answers "what did Midtrans tell us about
 * this order, and did we accept it?" — a question asked days after a payment,
 * not years. The money itself lives in the order tables and is never touched
 * here, the same split as views:prune leaving `event_view_daily` alone.
 *
 * This is not optional housekeeping: rows are written *before* the signature is
 * checked, so anyone who can reach the webhook URL can grow this table.
 */
class PruneMidtransNotifications extends Command
{
    protected $signature = 'payments:prune-notifications {--days=365} {--dry-run}';

    protected $description = 'Hapus log notifikasi Midtrans yang sudah lewat masa simpan (data pesanannya tetap disimpan).';

    public function handle(): int
    {
        $days = max((int) $this->option('days'), 1);
        $cutoff = Carbon::now()->subDays($days);

        $query = fn () => DB::table('payment_notifications')->where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info("[dry-run] {$query()->count()} baris sebelum {$cutoff->toDateTimeString()} akan dihapus.");

            return self::SUCCESS;
        }

        // Batched so a long-neglected table cannot hold a lock across the whole
        // sweep — every Midtrans notification writes to it.
        $deleted = 0;

        do {
            $batch = $query()->limit(10000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("{$deleted} baris log notifikasi sebelum {$cutoff->toDateTimeString()} dihapus.");

        return self::SUCCESS;
    }
}
