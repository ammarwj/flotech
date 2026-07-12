<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Read-only check of the ledger identity:
 *   balance_available = SUM(±amount WHERE status = 'available')
 *   balance_pending   = SUM(±amount WHERE status = 'pending')
 *
 * Balances are denormalized so withdrawals can lock a single row; this is the
 * insurance against a future code path that moves money without the lock.
 */
class AuditWallets extends Command
{
    protected $signature = 'wallet:audit';

    protected $description = 'Bandingkan saldo dompet dengan total ledger dan laporkan selisih.';

    public function handle(): int
    {
        $drifted = 0;

        foreach (Wallet::cursor() as $wallet) {
            $available = $this->sumFor($wallet->id, 'available');
            $pending = $this->sumFor($wallet->id, 'pending');

            $availableDrift = round((float) $wallet->balance_available - $available, 2);
            $pendingDrift = round((float) $wallet->balance_pending - $pending, 2);

            if ($availableDrift === 0.0 && $pendingDrift === 0.0) {
                continue;
            }

            $drifted++;
            $message = "Wallet {$wallet->id} (org {$wallet->organization_id}) tidak sinkron: "
                ."available {$wallet->balance_available} vs ledger {$available}, "
                ."pending {$wallet->balance_pending} vs ledger {$pending}.";

            $this->error($message);
            Log::error('[wallet:audit] '.$message);
        }

        if ($drifted > 0) {
            $this->error("{$drifted} dompet tidak sinkron dengan ledger.");

            return self::FAILURE;
        }

        $this->info('Semua dompet sinkron dengan ledger.');

        return self::SUCCESS;
    }

    protected function sumFor(string $walletId, string $status): float
    {
        $rows = WalletTransaction::where('wallet_id', $walletId)->where('status', $status)->get();

        return round($rows->sum(fn (WalletTransaction $tx) => $tx->signedAmount()), 2);
    }
}
