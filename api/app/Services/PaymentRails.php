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
 * Two rules, and only one of them is the event's. Each event picks its own rail
 * in `events.payment_method`: Midtrans, or manual bank transfer where the buyer
 * pays the organizer's own account and uploads proof. Above that sits the super
 * admin's global switch — when Midtrans is down they turn it off and *every*
 * event is forced onto manual, whatever it picked. The switch overrides; it
 * never negotiates.
 *
 * Manual money never reaches the platform, so there is nothing to take a fee
 * from: an event on manual sells at a zero `platform_fee` and writes nothing to
 * the wallet. That is the price of letting organizers choose, and it is a
 * deliberate one.
 *
 * Ticket purchase, registration payment and plan checkout all ask this, so the
 * rule lives here rather than in three controllers. The first two collect money
 * *for* an organizer; the third collects it *from* one — see the two
 * destination methods below, which differ by more than their return type.
 */
class PaymentRails
{
    public function __construct(protected PlanGate $gate) {}

    /**
     * True while the super admin has the gateway switched off platform-wide.
     *
     * Not "is this payment manual?" — an event that picked manual is manual with
     * the gateway perfectly healthy. Ask methodFor() that question.
     */
    public function gatewayIsDown(): bool
    {
        return ! PlatformSettings::paymentGatewayEnabled();
    }

    /**
     * The rail this event's next paid order will be born on.
     *
     * The event's own choice, unless the platform overrides it: while the
     * gateway is off everything is manual, and an event that picked the gateway
     * gets no exemption from the outage.
     *
     * Public because EventResource publishes exactly this answer. The dashboard
     * must never recombine the two rules itself — a second copy of "global AND
     * event" is how the organizer's screen and the buyer's checkout end up
     * disagreeing about the same event, the same shape of bug as the two readers
     * of `stage`.
     */
    public function methodFor(Event $event): string
    {
        return $this->gatewayIsDown() || $event->payment_method === 'manual' ? 'manual' : 'gateway';
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
     * Event-scoped, not org-scoped, twice over: the rail is picked per event and
     * the gateway entitlement is bought per event, so two events of one
     * organizer can legitimately answer differently. The manual destination is
     * still the organizer's own account — money on that rail never reaches the
     * platform, which is why platformDestination() is a separate method and
     * stays planless.
     *
     * @throws PaymentException when the organizer can't collect this money at all
     */
    public function destinationFor(Event $event, float $amount): ?BankAccount
    {
        // Free events collect nothing, so they need no rail and no entitlement.
        if ($amount <= 0) {
            return null;
        }

        if ($this->methodFor($event) === 'manual') {
            $bank = $event->organization->bankAccounts()->where('is_primary', true)->first();

            // Two messages, because the buyer can act on the difference: an
            // outage is temporary and nobody's fault, a chosen rail with no
            // account is setup the organizer still owes them.
            if (! $bank) {
                throw new PaymentException($this->gatewayIsDown()
                    ? 'Pembayaran sedang dialihkan ke transfer manual, tetapi penyelenggara belum menyiapkan rekening tujuan. Hubungi penyelenggara.'
                    : 'Penyelenggara event ini menerima pembayaran lewat transfer manual, tetapi belum menyiapkan rekening tujuan. Hubungi penyelenggara.',
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
     * with no entitlement anywhere — and the one that reads the global switch
     * alone, since there is no event here to have chosen a rail.
     *
     * @throws PaymentException when the gateway is off and no platform account is on file
     */
    public function platformDestination(float $amount): ?SiteSetting
    {
        // A free plan collects nothing, so it needs no rail.
        if ($amount <= 0) {
            return null;
        }

        if (! $this->gatewayIsDown()) {
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
