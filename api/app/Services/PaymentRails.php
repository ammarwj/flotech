<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Models\BankAccount;
use App\Models\Organization;
use Illuminate\Support\Carbon;

/**
 * Which rail a payment travels on, and whether it may travel at all.
 *
 * Normally Midtrans. When a super admin switches the gateway off (Midtrans is
 * down), every organization falls back to manual bank transfer: the buyer pays
 * the organizer's own account and uploads proof. That fallback is never an
 * organization's choice — manual money never reaches the platform, so there is
 * nothing to take a fee from, and letting organizers opt in would simply end
 * fee revenue.
 *
 * Ticket purchase and registration payment both ask this, so the rule lives
 * here rather than in three controllers.
 */
class PaymentRails
{
    public function __construct(protected PlanGate $gate) {}

    /** True while the gateway is switched off and everyone is on manual transfer. */
    public function isManual(): bool
    {
        return ! PlatformSettings::paymentGatewayEnabled();
    }

    /** How long a manual order may sit unpaid before `tickets:expire-manual` voids it. */
    public function deadline(): Carbon
    {
        return Carbon::now()->addHours((int) config('payments.manual_order_ttl_hours'));
    }

    /**
     * The account a buyer must transfer to — and the signal that this payment is
     * a manual one. Returns null when it goes through the gateway or costs
     * nothing, so callers can read `$bank !== null` as "this one is manual".
     *
     * @throws PaymentException when the organizer can't collect this money at all
     */
    public function destinationFor(Organization $org, float $amount): ?BankAccount
    {
        // Free events collect nothing, so they need no rail and no entitlement.
        if ($amount <= 0) {
            return null;
        }

        if ($this->isManual()) {
            $bank = $org->bankAccounts()->where('is_primary', true)->first();

            if (! $bank) {
                throw new PaymentException(
                    'Pembayaran sedang dialihkan ke transfer manual, tetapi penyelenggara belum menyiapkan rekening tujuan. Hubungi penyelenggara.',
                );
            }

            return $bank;
        }

        if (! $this->gate->allows($org, 'payment_gateway')) {
            throw new PaymentException(
                'Penyelenggara tidak dapat menerima pembayaran online untuk saat ini.',
                ['feature' => 'payment_gateway'],
                403,
            );
        }

        return null;
    }
}
