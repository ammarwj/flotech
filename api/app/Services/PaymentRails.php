<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Models\BankAccount;
use App\Models\Event;
use App\Models\SiteSetting;
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
 * Ticket purchase, registration payment and plan checkout all ask this, so the
 * rule lives here rather than in three controllers. The first two collect money
 * *for* an organizer; the third collects it *from* one — see the two
 * destination methods below, which differ by more than their return type.
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
     * Event-scoped, not org-scoped: the gateway entitlement is bought per event,
     * so two events of one organizer can legitimately answer differently. The
     * manual destination is still the organizer's own account — money on that
     * rail never reaches the platform, which is why platformDestination() is a
     * separate method and stays planless.
     *
     * @throws PaymentException when the organizer can't collect this money at all
     */
    public function destinationFor(Event $event, float $amount): ?BankAccount
    {
        // Free events collect nothing, so they need no rail and no entitlement.
        if ($amount <= 0) {
            return null;
        }

        if ($this->isManual()) {
            $bank = $event->organization->bankAccounts()->where('is_primary', true)->first();

            if (! $bank) {
                throw new PaymentException(
                    'Pembayaran sedang dialihkan ke transfer manual, tetapi penyelenggara belum menyiapkan rekening tujuan. Hubungi penyelenggara.',
                );
            }

            return $bank;
        }

        if (! $this->gate->allows($event, 'payment_gateway')) {
            throw new PaymentException(
                'Penyelenggara tidak dapat menerima pembayaran online untuk event ini.',
                ['feature' => 'payment_gateway'],
                403,
            );
        }

        return null;
    }

    /**
     * The account an organizer pays *us* into for a plan — and the signal that
     * this payment is a manual one. Null means Midtrans, or free.
     *
     * Deliberately not destinationFor(). That one answers "where does a buyer
     * send money to this organizer", and it also demands the `payment_gateway`
     * entitlement of a specific event. Plan money flows the other way, and there
     * is no event to read an entitlement from: the organizer pays first and
     * creates the event afterwards. Buying a plan is the one flow that must work
     * with no entitlement anywhere.
     *
     * @throws PaymentException when the gateway is off and no platform account is on file
     */
    public function platformDestination(float $amount): ?SiteSetting
    {
        // A free plan collects nothing, so it needs no rail.
        if ($amount <= 0) {
            return null;
        }

        if (! $this->isManual()) {
            return null;
        }

        $settings = SiteSetting::current();

        if (! $settings->hasBankAccount()) {
            throw new PaymentException(
                'Pembayaran paket sedang dialihkan ke transfer manual, tetapi rekening tujuan belum disiapkan. Hubungi admin flo-event.',
            );
        }

        return $settings;
    }
}
