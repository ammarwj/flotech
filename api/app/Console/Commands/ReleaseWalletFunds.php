<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\WalletService;
use Illuminate\Console\Command;

class ReleaseWalletFunds extends Command
{
    protected $signature = 'wallet:release {--event= : Hanya rilis dana event ini}';

    protected $description = 'Cairkan saldo tertahan menjadi saldo tersedia untuk event yang sudah selesai.';

    public function handle(WalletService $wallet): int
    {
        if ($eventId = $this->option('event')) {
            $event = Event::find($eventId);

            if (! $event) {
                $this->error("Event {$eventId} tidak ditemukan.");

                return self::FAILURE;
            }

            $count = $wallet->releaseEvent($event);
        } else {
            $count = $wallet->releaseDue();
        }

        $this->info("{$count} transaksi dompet dirilis.");

        return self::SUCCESS;
    }
}
