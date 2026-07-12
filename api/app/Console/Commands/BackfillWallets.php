<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\TicketOrder;
use App\Services\WalletService;
use Illuminate\Console\Command;

/**
 * One-off: build wallets for money collected before this feature existed.
 * Without it every organizer opens the wallet page and sees Rp 0 while the
 * platform is holding their revenue.
 *
 * Idempotent — the ledger's unique source index makes a re-run a no-op — so
 * it is safe to run repeatedly on the same database.
 */
class BackfillWallets extends Command
{
    protected $signature = 'wallet:backfill';

    protected $description = 'Buat entri dompet untuk pesanan tiket & pendaftaran lunas yang sudah ada.';

    public function handle(WalletService $wallet): int
    {
        $credited = 0;

        TicketOrder::where('status', 'paid')
            ->with('event.organization')
            ->chunkById(200, function ($orders) use ($wallet, &$credited) {
                foreach ($orders as $order) {
                    if ($order->event?->organization && $wallet->creditTicketOrder($order)) {
                        $credited++;
                    }
                }
            });

        Team::where('payment_status', 'paid')
            ->where('payment_amount', '>', 0)
            ->with('event.organization')
            ->chunkById(200, function ($teams) use ($wallet, &$credited) {
                foreach ($teams as $team) {
                    if ($team->event?->organization && $wallet->creditRegistration($team)) {
                        $credited++;
                    }
                }
            });

        $this->info("{$credited} entri dompet dibuat (entri yang sudah ada dilewati).");

        // Past events settle straight into the available balance.
        $released = $wallet->releaseDue();
        $this->info("{$released} transaksi langsung dirilis ke saldo tersedia.");

        return self::SUCCESS;
    }
}
