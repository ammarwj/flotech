<?php

namespace App\Console\Commands;

use App\Services\WalletService;
use Illuminate\Console\Command;

class ReleaseWalletFunds extends Command
{
    protected $signature = 'wallet:release';

    protected $description = 'Cairkan saldo tertahan yang sudah lewat batas waktunya.';

    /**
     * No --event option: the clock is the only thing that releases funds, so
     * there is nothing an event-scoped run could do that this one does not.
     */
    public function handle(WalletService $wallet): int
    {
        $count = $wallet->releaseDue();

        $this->info("{$count} transaksi dompet dirilis.");

        return self::SUCCESS;
    }
}
