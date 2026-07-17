<?php

namespace App\Console\Commands;

use App\Models\TicketOrder;
use App\Services\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Release the quota held by manual orders nobody ever paid.
 *
 * The gateway rail doesn't need this — Midtrans expires an abandoned order and
 * its webhook cancels ours. A manual transfer has no webhook at all, so without
 * this sweep one abandoned order would hold its seats until the organizer
 * noticed, and a busy event would read "sold out" on ghost orders.
 *
 * An order whose buyer *has* uploaded proof is never touched: it is waiting on
 * the organizer, and expiring someone's paid seat because staff were slow would
 * be the worse failure.
 */
class ExpireManualOrders extends Command
{
    protected $signature = 'tickets:expire-manual';

    protected $description = 'Batalkan pesanan transfer manual yang lewat tenggat dan belum mengunggah bukti, lalu kembalikan kuotanya.';

    public function handle(TicketService $tickets): int
    {
        $expired = TicketOrder::query()
            ->where('payment_method', 'manual')
            ->where('status', 'pending')
            ->whereNull('payment_proof_url')
            ->whereNotNull('payment_deadline_at')
            ->where('payment_deadline_at', '<', Carbon::now())
            ->get();

        foreach ($expired as $order) {
            // cancel() is what decrements `sold` and voids the issued tickets.
            $tickets->cancel($order, 'expired');
        }

        $this->info("{$expired->count()} pesanan transfer manual kedaluwarsa.");

        return self::SUCCESS;
    }
}
