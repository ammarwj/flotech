<?php

namespace App\Services;

use App\Exceptions\WalletException;
use App\Models\Organization;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Notifications\WithdrawalCompleted;
use App\Notifications\WithdrawalRejected;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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

            // Money from events that are over is exempt from the minimum: there
            // is no next sale to top it up with, so a floor there would just
            // trap the last few rupiah forever.
            //
            // Compared against `$amount`, not `$totalDebit` — matching on the
            // total would refuse a payout for exactly the exempt balance and put
            // that money straight back in the trap. The admin fee is therefore
            // taken from the general balance, and is not waived.
            //
            // Rounded with a cent of slack because `decimal:2` casts and PDO's
            // SUM() over decimals both come back as strings: without it a waiver
            // of 39999.999999999 refuses a withdrawal of exactly 40000.
            $waived = round($wallet->minimumWaivedBalance(), 2);

            // Whether the waiver is what let this payout through. A request at
            // or above the minimum needed no exemption, so it must not draw on
            // the stock — money is fungible and the ordinary balance is what it
            // came from. Consuming unconditionally is the same bug as the
            // stateless formula wearing a different hat: one Rp 300.000 payout
            // would wipe out a waiver it never used, and the organizer's last
            // Rp 40.000 from a closed event would be trapped for good.
            $usesWaiver = $amount < $minimum;

            if ($amount > $waived + 0.001 && $usesWaiver) {
                throw new WalletException(
                    'Minimal penarikan adalah Rp '.number_format($minimum, 0, ',', '.')
                    .'. Saldo dari event yang sudah berakhir bisa ditarik berapa pun.',
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
                // The configured value even when the payout was waived: 0 is a
                // legal setting for the minimum, so writing 0 here would make
                // "waived" indistinguishable from "the admin set it to zero" —
                // in exactly the record a dispute over a below-minimum payout
                // would be settled from. `exempt_consumed` is the marker.
                'minimum_at_request' => $minimum,
                'exempt_consumed' => $usesWaiver ? min($totalDebit, $waived) : 0,
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

        $fresh = $withdrawal->fresh();
        $this->mail($fresh, new WithdrawalCompleted($fresh));

        return $fresh;
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

        $fresh = $withdrawal->fresh();
        $this->mail($fresh, new WithdrawalRejected($fresh, $reason));

        return $fresh;
    }

    /**
     * Tell the organizer's owner what happened to their money. Sent after the
     * ledger has committed, and swallows its own errors — the transfer is already
     * done, so a queue hiccup must not fail the admin's request and tempt them
     * into pressing the button twice.
     *
     * ensureOpen() already refuses a withdrawal that isn't open, so neither of
     * these can be sent twice for one payout.
     */
    protected function mail(Withdrawal $withdrawal, Notification $notification): void
    {
        try {
            $withdrawal->organization->owner?->notify($notification);
        } catch (Throwable $e) {
            Log::error('Gagal mengirim email penarikan dana', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);
        }
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
