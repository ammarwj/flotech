<?php

namespace App\Console\Commands;

use App\Models\RaceEntryTransfer;
use App\Services\RaceTransferService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Release the TARGET places held by category moves nobody ever paid for.
 *
 * Twin of race-entries:expire-manual, and the same three rules hold for the
 * same reasons: the gateway rail does not need it (Midtrans expires an
 * abandoned order and its webhook cancels ours), a move whose runner HAS
 * uploaded proof is never touched (it is waiting on the organizer, and
 * expiring a paid-for place because staff were slow is the worse failure), and
 * the status written is `expired` rather than `cancelled` so the organizer can
 * tell a runner who changed their mind from one the clock ran out on.
 *
 * What makes this sweep load-bearing rather than tidy-up is the ordering
 * `RaceTransferService::start()` chose: an open move holds a place in the
 * TARGET category while the runner still holds their original one, because a
 * move that is never paid for must leave them exactly where they were. Two
 * places per open move is the price of that, and this is what gives the second
 * one back. Without it one abandoned move reads as "kuota penuh" on a category
 * nobody is running in.
 *
 * The ENTRY is untouched. `cancel()` only ever decrements the target, which is
 * the one place that was speculatively held.
 */
class ExpireManualRaceTransfers extends Command
{
    protected $signature = 'race-transfers:expire-manual';

    protected $description = 'Batalkan pindah kategori transfer manual yang lewat tenggat dan belum mengunggah bukti, lalu kembalikan kuota kategori tujuan.';

    public function handle(RaceTransferService $transfers): int
    {
        $expired = RaceEntryTransfer::query()
            ->where('payment_method', 'manual')
            ->where('status', 'pending')
            ->whereNull('payment_proof_url')
            ->whereNotNull('payment_deadline_at')
            ->where('payment_deadline_at', '<', Carbon::now())
            ->get();

        foreach ($expired as $transfer) {
            // cancel() is what decrements `taken` — never a bare update() here,
            // or the target place stays held by a row that reads expired.
            $transfers->cancel($transfer, 'expired');
        }

        $this->info("{$expired->count()} pindah kategori transfer manual kedaluwarsa.");

        return self::SUCCESS;
    }
}
