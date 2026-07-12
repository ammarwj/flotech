<?php

namespace App\Services;

use App\Exceptions\WalletException;
use App\Models\Organization;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payout requests. Funds are debited the moment a request is created, so the
 * organizer can't spend the same balance twice while an admin is transferring
 * it; rejecting or cancelling credits them back.
 */
class WithdrawalService
{
    public function __construct(protected WalletService $wallet) {}

    /**
     * Create a payout request. Every check runs inside the wallet row lock, so
     * two simultaneous requests can't both pass the balance test.
     */
    public function request(Organization $org, User $user, float $amount, ?string $note = null): Withdrawal
    {
        return DB::transaction(function () use ($org, $user, $amount, $note) {
            $wallet = Wallet::where('organization_id', $org->id)->lockForUpdate()->first()
                ?? $this->wallet->forOrganization($org);

            if ($org->withdrawals()->whereIn('status', ['pending', 'processing'])->exists()) {
                throw new WalletException('Masih ada permintaan penarikan yang sedang diproses.');
            }

            $bank = $org->bankAccounts()->where('is_primary', true)->first();
            if (! $bank) {
                throw new WalletException('Tambahkan rekening bank terlebih dahulu.');
            }

            // Super-admin editable (config/wallet.php holds the defaults). The
            // withdrawal snapshots both, so changing them never rewrites history.
            $minimum = PlatformSettings::minimumWithdrawal();
            $fee = PlatformSettings::adminFee();

            if ($amount < $minimum) {
                throw new WalletException(
                    'Minimal penarikan adalah Rp '.number_format($minimum, 0, ',', '.').'.',
                    ['amount' => 'Jumlah di bawah minimal penarikan.'],
                );
            }

            $totalDebit = round($amount + $fee, 2);

            if ((float) $wallet->balance_available < $totalDebit) {
                throw new WalletException(
                    'Saldo tersedia tidak mencukupi (sudah termasuk biaya admin Rp '.number_format($fee, 0, ',', '.').').',
                    ['amount' => 'Saldo tidak mencukupi.'],
                );
            }

            $withdrawal = Withdrawal::create([
                'organization_id' => $org->id,
                'wallet_id' => $wallet->id,
                'bank_account_id' => $bank->id,
                'reference' => 'WD-'.Str::upper(Str::random(8)),
                'amount' => $amount,
                'admin_fee' => $fee,
                'total_debit' => $totalDebit,
                'minimum_at_request' => $minimum,
                'status' => 'pending',
                'bank_name' => $bank->bank_name,
                'bank_code' => $bank->bank_code,
                'account_number' => $bank->account_number,
                'account_holder' => $bank->account_holder,
                'note' => $note,
                'requested_by' => $user->id,
            ]);

            $this->wallet->holdWithdrawal($wallet, $withdrawal);

            return $withdrawal;
        });
    }

    /** Admin picked it up and is making the transfer. No money moves. */
    public function process(Withdrawal $withdrawal, User $admin): Withdrawal
    {
        $this->ensureOpen($withdrawal);

        $withdrawal->update([
            'status' => 'processing',
            'processed_by' => $admin->id,
            'processed_at' => Carbon::now(),
        ]);

        return $withdrawal->fresh();
    }

    /**
     * The transfer happened. The wallet was already debited at request time, so
     * this writes no ledger row — only the lifetime total advances.
     */
    public function complete(Withdrawal $withdrawal, User $admin, string $proofUrl, ?string $transferReference = null, ?string $adminNote = null): Withdrawal
    {
        $this->ensureOpen($withdrawal);

        DB::transaction(function () use ($withdrawal, $admin, $proofUrl, $transferReference, $adminNote) {
            $withdrawal->update([
                'status' => 'completed',
                'proof_url' => $proofUrl,
                'transfer_reference' => $transferReference,
                'admin_note' => $adminNote,
                'processed_by' => $admin->id,
                'processed_at' => $withdrawal->processed_at ?? Carbon::now(),
                'completed_at' => Carbon::now(),
            ]);

            $this->wallet->settleWithdrawal($withdrawal);
        });

        return $withdrawal->fresh();
    }

    /** Refuse the payout and give the held funds back. */
    public function reject(Withdrawal $withdrawal, User $admin, string $reason): Withdrawal
    {
        $this->ensureOpen($withdrawal);

        DB::transaction(function () use ($withdrawal, $admin, $reason) {
            $withdrawal->update([
                'status' => 'rejected',
                'admin_note' => $reason,
                'processed_by' => $admin->id,
                'processed_at' => Carbon::now(),
            ]);

            $this->wallet->reverseWithdrawal($withdrawal);
        });

        return $withdrawal->fresh();
    }

    /** The organizer changed their mind — only while nobody has picked it up. */
    public function cancel(Withdrawal $withdrawal): Withdrawal
    {
        if ($withdrawal->status !== 'pending') {
            throw new WalletException('Penarikan yang sudah diproses tidak bisa dibatalkan.', null, 409);
        }

        DB::transaction(function () use ($withdrawal) {
            $withdrawal->update(['status' => 'rejected', 'admin_note' => 'Dibatalkan oleh organizer.']);
            $this->wallet->reverseWithdrawal($withdrawal);
        });

        return $withdrawal->fresh();
    }

    protected function ensureOpen(Withdrawal $withdrawal): void
    {
        if (! $withdrawal->isOpen()) {
            throw new WalletException('Penarikan ini sudah selesai atau ditolak.', null, 409);
        }
    }
}
