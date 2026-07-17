<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Models\BankAccount;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Notifications\RegistrationPaid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Registration-fee payment lifecycle for a team. Mirrors TicketService:
 * a team's `payment_status` flips to 'paid' on Midtrans settlement (or
 * immediately for free events / when no gateway is configured), or when an org
 * admin approves a manual transfer's proof.
 */
class RegistrationService
{
    public function __construct(
        protected PlanGate $gate,
        protected MidtransService $midtrans,
        protected WalletService $wallet,
        protected PaymentRails $rails,
    ) {}

    /**
     * Platform fee for a registration amount, based on the organizer plan's
     * `registration_fee_percent` feature (0 when unset).
     */
    public function platformFee(Organization $org, float $amount): float
    {
        $percent = (float) ($this->gate->value($org, 'registration_fee_percent') ?? 0);

        return round($amount * $percent / 100, 2);
    }

    /**
     * Start payment for a team's registration fee. Free registrations settle
     * immediately; otherwise it's Midtrans, or — while a super admin has the
     * gateway switched off — a manual transfer to the organizer's own account
     * that an org admin approves from the buyer's uploaded proof.
     *
     * @return array{snap_token: string|null, redirect_url: string|null, mock: bool, payment_method: string, bank_account: BankAccount|null}
     *
     * @throws PaymentException when the organizer can't collect
     */
    public function startPayment(Team $team, Organization $org): array
    {
        $amount = (float) $team->category->registration_fee;

        // Throws when the organizer has no gateway entitlement, or the gateway
        // is off and they never set up a bank account. A non-null account is
        // itself the signal that this payment is a manual one.
        $bank = $this->rails->destinationFor($org, $amount);
        $manual = $bank !== null;

        if ($amount <= 0) {
            $this->markPaid($team);

            return $this->result(null, null, true, 'gateway', null);
        }

        $orderId = $team->midtrans_order_id ?: 'REG-'.Str::upper(Str::random(10));

        $team->update([
            'payment_status' => 'unpaid',
            'payment_amount' => $amount,
            // Manual money never reaches us, so there is nothing to take a cut of.
            'platform_fee' => $manual ? 0 : $this->platformFee($org, $amount),
            'payment_method' => $manual ? 'manual' : 'gateway',
            'payment_deadline_at' => $manual ? $this->rails->deadline() : null,
            'midtrans_order_id' => $orderId,
        ]);

        if ($manual) {
            return $this->result(null, null, false, 'manual', $bank);
        }

        $snap = $this->midtrans->createSnapTransaction(
            ['order_id' => $orderId, 'gross_amount' => (int) round($amount)],
            ['first_name' => $team->contact_name ?? $team->name, 'email' => $org->contact_email],
            rtrim((string) config('app.frontend_url'), '/')."/{$org->slug}/{$team->event->slug}/register?status=success",
        );

        if ($snap['token']) {
            $team->update(['midtrans_token' => $snap['token']]);
        }

        if ($snap['mock']) {
            // No payment gateway configured — settle immediately for dev. Only
            // ever reached on the gateway rail: a manual registration returned
            // above and stays unpaid until its proof is approved.
            $this->markPaid($team);
        }

        return $this->result($snap['token'], $snap['redirect_url'], $snap['mock'], 'gateway', null);
    }

    /**
     * The team contact uploads their transfer receipt for an org admin to check.
     *
     * @throws PaymentException
     */
    public function submitProof(Team $team, string $proofUrl): void
    {
        if (! $team->isManual()) {
            throw new PaymentException('Pendaftaran ini tidak dibayar lewat transfer manual.');
        }

        if ($team->payment_status === 'paid') {
            throw new PaymentException('Pendaftaran ini sudah dibayar.');
        }

        $team->attachProof($proofUrl);
    }

    /**
     * Accept a manual transfer. markPaid() is what keeps this off the wallet —
     * the money went to the organizer's own account, not ours.
     *
     * @throws PaymentException
     */
    public function approveProof(Team $team, User $admin): void
    {
        if (! $team->isAwaitingVerification()) {
            throw new PaymentException('Tidak ada bukti pembayaran yang menunggu verifikasi.');
        }

        $team->markVerified($admin);
        $this->markPaid($team->fresh());
    }

    /**
     * @throws PaymentException
     */
    public function rejectProof(Team $team, string $reason, Carbon $deadline): void
    {
        if (! $team->isAwaitingVerification()) {
            throw new PaymentException('Tidak ada bukti pembayaran yang menunggu verifikasi.');
        }

        $team->rejectProof($reason, $deadline);
    }

    /**
     * @return array{snap_token: string|null, redirect_url: string|null, mock: bool, payment_method: string, bank_account: BankAccount|null}
     */
    private function result(?string $token, ?string $redirectUrl, bool $mock, string $method, ?BankAccount $bank): array
    {
        return [
            'snap_token' => $token,
            'redirect_url' => $redirectUrl,
            'mock' => $mock,
            'payment_method' => $method,
            'bank_account' => $bank,
        ];
    }

    /**
     * Settle a paid registration and credit the organizer's wallet. Idempotent
     * — re-delivered webhooks are no-ops.
     */
    public function markPaid(Team $team): void
    {
        if ($team->payment_status === 'paid') {
            return;
        }

        DB::transaction(function () use ($team) {
            $team->update(['payment_status' => 'paid', 'paid_at' => Carbon::now()]);

            // A manual transfer went straight into the organizer's own bank
            // account — the money never passed through us, so crediting the
            // wallet would make their balance claim funds we are not holding.
            // Same reasoning as the offline team entry in RegistrationController.
            if (! $team->isManual()) {
                $this->wallet->creditRegistration($team->load('event.organization'));
            }
        });

        $this->sendPaymentConfirmation($team);
    }

    /**
     * Receipt for the manager, sent after the money is banked — never inside the
     * transaction. Swallows its own errors for the same reason TicketService does:
     * the payment is settled, and a queue hiccup must not bubble into the Midtrans
     * webhook and provoke a retry. A team entered offline has no manager account
     * and gets nothing; the teams table holds a phone number, not an email.
     */
    protected function sendPaymentConfirmation(Team $team): void
    {
        try {
            $team->manager?->notify(new RegistrationPaid($team->load('event')));
        } catch (Throwable $e) {
            Log::error('Gagal mengirim email pembayaran pendaftaran', [
                'team_id' => $team->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
