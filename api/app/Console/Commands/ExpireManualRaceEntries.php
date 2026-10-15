<?php

namespace App\Console\Commands;

use App\Models\RaceEntry;
use App\Services\RaceEntryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Release the places held by manual entries nobody ever paid.
 *
 * Twin of tickets:expire-manual, and the same three rules hold for the same
 * reasons: the gateway rail does not need it (Midtrans expires an abandoned
 * order and its webhook cancels ours), an entry whose runner *has* uploaded
 * proof is never touched (it is waiting on the organizer, and expiring a paid
 * place because staff were slow is the worse failure), and the status written
 * is `expired` rather than `cancelled` so the organizer's list can tell a
 * runner who gave up from one the clock ran out on.
 *
 * Without this sweep one abandoned entry holds a place until somebody notices,
 * and a race with a real cap reads "kuota penuh" on entries nobody is running.
 */
class ExpireManualRaceEntries extends Command
{
    protected $signature = 'race-entries:expire-manual';

    protected $description = 'Batalkan entry lomba transfer manual yang lewat tenggat dan belum mengunggah bukti, lalu kembalikan kuotanya.';

    public function handle(RaceEntryService $entries): int
    {
        $expired = RaceEntry::query()
            ->where('payment_method', 'manual')
            ->where('status', 'pending')
            ->whereNull('payment_proof_url')
            ->whereNotNull('payment_deadline_at')
            ->where('payment_deadline_at', '<', Carbon::now())
            ->get();

        foreach ($expired as $entry) {
            // cancel() is what decrements `taken` — never a bare update() here,
            // or the place stays held by a row that reads expired.
            $entries->cancel($entry, 'expired');
        }

        $this->info("{$expired->count()} entry lomba transfer manual kedaluwarsa.");

        return self::SUCCESS;
    }
}
