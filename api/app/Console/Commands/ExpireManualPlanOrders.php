<?php

namespace App\Console\Commands;

use App\Models\EventPlanOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Cancel manual plan bills nobody ever paid.
 *
 * The sibling sweep on ticket orders exists to release held quota. This one
 * holds nothing — a plan order reserves no seats. What it enforces is the
 * deadline the transfer panel prints: an unenforced deadline is worse than
 * none, and without it "Bayar sekarang" would keep offering the price it was raised at
 * on a bill that has long since gone stale.
 *
 * A bill whose organizer *has* uploaded proof is never touched. That is also
 * what keeps EventPlanOrder::scopeAwaitingVerification() honest — it matches on
 * `status != 'paid'`, so a cancelled row carrying a proof would sit in the
 * super admin's queue forever.
 *
 * Invoice numbers are not recycled, and that is deliberate: a cancelled invoice
 * is a document that was issued and emailed, so reissuing its number would put
 * two different documents under one. nextNumber() takes max + 1 rather than
 * filling gaps, so the sequence simply skips — which is already what happens to
 * an abandoned gateway checkout today.
 */
class ExpireManualPlanOrders extends Command
{
    protected $signature = 'plan-orders:expire-manual';

    protected $description = 'Batalkan tagihan paket transfer manual yang lewat tenggat dan belum mengunggah bukti.';

    public function handle(): int
    {
        $count = EventPlanOrder::query()
            ->where('payment_method', 'manual')
            ->where('status', 'past_due')
            ->whereNull('payment_proof_url')
            ->whereNotNull('payment_deadline_at')
            ->where('payment_deadline_at', '<', Carbon::now())
            ->update(['status' => 'cancelled']);

        $this->info("{$count} tagihan paket transfer manual kedaluwarsa.");

        return self::SUCCESS;
    }
}
