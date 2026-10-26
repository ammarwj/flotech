<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Models\BankAccount;
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
        protected MidtransService $midtrans,
        protected WalletService $wallet,
        protected PaymentRails $rails,
        protected PaymentFeeCalculator $fees,
        protected DocumentNumberService $numbers,
        protected EventDiscountCodeService $discounts,
    ) {}

    /** @param  'invoice'|'receipt'  $kind */
    protected function number(string $kind): string
    {
        return $this->numbers->next(
            Team::class,
            "{$kind}_number",
            config("billing.registration_{$kind}_prefix"),
        );
    }

    /**
     * Start payment for a team's registration fee. Free registrations settle
     * immediately; otherwise it's Midtrans, or — while a super admin has the
     * gateway switched off — a manual transfer to the organizer's own account
     * that an org admin approves from the buyer's uploaded proof.
     *
     * `$channel` is required exactly when the payment turns out to be a paid
     * gateway one — checked here, not in each caller, because both callers
     * (MyTeamController::pay, PublicEventController::register) would otherwise
     * have to duplicate "is this manual or free" to know whether to demand it.
     *
     * `$discountCode` is optional and only read on the FIRST attempt — see
     * discountedAmount().
     *
     * @return array{snap_token: string|null, redirect_url: string|null, mock: bool, payment_method: string, bank_account: BankAccount|null}
     *
     * @throws PaymentException when the organizer can't collect, or a gateway payment has no channel
     * @throws \Illuminate\Validation\ValidationException when the discount code cannot be used
     */
    public function startPayment(Team $team, ?string $channel = null, ?string $discountCode = null): array
    {
        // The rail is read off the event now, so the caller no longer passes
        // the organization in. PublicEventController::register() sets the
        // `event` relation on the fresh team for the same reason it sets
        // `category` — so this does not re-query it.
        $event = $team->event;
        $org = $event->organization;
        $amount = (float) $team->category->registration_fee;

        // Before destinationFor() and before any fee is computed, and that
        // ordering is the invariant: forChannel() takes a percentage of
        // $amount, and destinationFor() branches on $amount <= 0. A discount
        // applied afterwards leaves `gateway_fee` disagreeing with what
        // Midtrans is actually charged.
        $discount = $this->discountedAmount($team, $amount, $discountCode);
        $amount = $discount['amount'];

        // Throws when the event has no gateway entitlement, or the gateway is
        // off and the organizer never set up a bank account. A non-null account
        // is itself the signal that this payment is a manual one.
        $bank = $this->rails->destinationFor($event, $amount);
        $manual = $bank !== null;

        if ($amount <= 0) {
            // A 100% code lands here, which is why the snapshot is written
            // before settling rather than only in the update() below: without
            // it the registration goes through free while the row claims it was
            // never discounted, and the code's quota is never spent. No
            // document is issued, the same rule a genuinely free category
            // follows — a Rp 0 invoice claims a transaction that never happened.
            if ($discount['columns'] !== []) {
                $team->update(['payment_amount' => $amount, ...$discount['columns']]);
            }

            $this->markPaid($team);

            return $this->result(null, null, true, 'gateway', null);
        }

        $gatewayFee = 0.0;
        $gatewayTax = 0.0;
        $serviceFee = 0.0;
        $enabledPayments = [];

        if (! $manual) {
            if (! $channel) {
                throw new PaymentException(
                    'Pilih metode pembayaran.',
                    ['payment_channel' => ['Metode pembayaran wajib dipilih.']],
                );
            }

            $breakdown = $this->fees->forChannel(
                $channel,
                $amount,
                PaymentFeeCalculator::AUDIENCE_REGISTRATION,
            );
            $gatewayFee = $breakdown['gateway_fee'];
            $gatewayTax = $breakdown['gateway_tax'];
            $serviceFee = $breakdown['service_fee'];
            $enabledPayments = $breakdown['midtrans_payments'];
        }

        $orderId = $team->midtrans_order_id ?: 'REG-'.Str::upper(Str::random(10));

        $team->update([
            'payment_status' => 'unpaid',
            // Net of the discount. That net is also what the wallet is
            // credited (WalletService::creditRegistration reads this column),
            // which is how "the organizer bears the discount" holds with no
            // second rule anywhere.
            'payment_amount' => $amount,
            ...$discount['columns'],
            // Kept across a retry: reopening payment is the same bill, so it
            // must not burn a second invoice number. Free entries get none.
            'invoice_number' => $team->invoice_number ?? ($amount > 0 ? $this->number('invoice') : null),
            // Manual money never reaches us, so there is nothing to take a cut of.
            'platform_fee' => 0,
            'payment_method' => $manual ? 'manual' : 'gateway',
            'payment_channel' => $manual ? null : $channel,
            'gateway_fee' => $gatewayFee,
            // Already inside gateway_fee; stored so the PDF can show the tax
            // as its own line without recomputing a rate that moves.
            'gateway_tax' => $gatewayTax,
            'service_fee' => $serviceFee,
            'payment_deadline_at' => $manual ? $this->rails->deadline() : null,
            'midtrans_order_id' => $orderId,
        ]);

        if ($manual) {
            return $this->result(null, null, false, 'manual', $bank);
        }

        $snap = $this->midtrans->createSnapTransaction(
            ['order_id' => $orderId, 'gross_amount' => (int) round($team->gross_amount)],
            ['first_name' => $team->contact_name ?? $team->name, 'email' => $org->contact_email],
            rtrim((string) config('app.frontend_url'), '/')."/{$org->slug}/{$team->event->slug}/register?status=success",
            $enabledPayments,
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
     * The registration's price after any discount, plus the columns that record it.
     *
     * **A retry reads the stored snapshot and ignores whatever code was sent.**
     * Reopening payment is the same bill: resolving again would spend a second
     * quota slot and could change the amount on an invoice already issued. Same
     * reasoning as `invoice_number` being kept across a retry a few lines below.
     *
     * The first attempt is the only one that resolves, and `columns` is empty
     * when no code was given — so a registration without one writes nothing and
     * behaves exactly as it did before this feature existed.
     *
     * @return array{amount: float, columns: array<string, mixed>}
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function discountedAmount(Team $team, float $amount, ?string $code): array
    {
        if ($team->event_discount_code_id !== null) {
            return [
                'amount' => round($amount - (float) $team->discount_amount, 2),
                'columns' => [],
            ];
        }

        $resolved = $this->discounts->resolve($code, $team->event, $amount, $team->manager_user_id);

        if ($resolved === null) {
            return ['amount' => $amount, 'columns' => []];
        }

        return [
            'amount' => $resolved['amount'],
            'columns' => [
                'event_discount_code_id' => $resolved['code']->id,
                'discount_code' => $resolved['code']->code,
                'discount_type' => $resolved['code']->discount_type,
                'discount_value' => $resolved['code']->discount_value,
                'discount_amount' => $resolved['discount'],
            ],
        ];
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
     *
     * `$paymentType` is the Midtrans instrument, known only to the webhook —
     * see TicketService::markPaid() for why it defaults to null and is written
     * with `??` rather than assigned.
     */
    public function markPaid(Team $team, ?string $paymentType = null): void
    {
        if ($team->payment_status === 'paid') {
            return;
        }

        DB::transaction(function () use ($team, $paymentType) {
            $team->update([
                'payment_status' => 'paid',
                'paid_at' => Carbon::now(),
                // `??` keeps a re-delivered webhook from issuing a second
                // receipt for one payment; free entries never get one at all.
                'receipt_number' => $team->receipt_number
                    ?? ((float) $team->payment_amount > 0 ? $this->number('receipt') : null),
                'payment_type' => $paymentType ?? $team->payment_type,
            ]);

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
