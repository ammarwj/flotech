<?php

namespace App\Services;

use App\Exceptions\PaymentException;

/**
 * The single place the buyer-paid fee formula is computed — used by both the
 * channel-picker preview endpoint and every order-creation path (tickets,
 * registrations, plan orders), so the number a buyer sees before paying is
 * exactly the number they get charged.
 *
 * No rounding anywhere: the breakdown feeds straight into `gross_amount`
 * columns and Midtrans, and rounding here would just move the discrepancy
 * somewhere harder to find.
 */
class PaymentFeeCalculator
{
    /**
     * Participant buying tickets from an organizer. Charged per seat, so a
     * basket of three carries three of them.
     */
    public const AUDIENCE_TICKET = 'ticket_service_fee_amount';

    /**
     * Participant paying a team's registration fee to an organizer. Charged
     * once per team — the roster size never multiplies it.
     *
     * Separate from AUDIENCE_TICKET although both are participants paying an
     * organizer: a registration costs many times a ticket and is bought once,
     * so a rate that suits either is wrong for the other.
     */
    public const AUDIENCE_REGISTRATION = 'registration_service_fee_amount';

    /** Organizer paying the platform: event plan purchases and upgrades. */
    public const AUDIENCE_ORGANIZER = 'plan_service_fee_amount';

    /**
     * Breakdown for one channel. Throws when the channel is unknown or has
     * been disabled (e.g. `retail` before it's turned on).
     *
     * `$audience` picks which platform margin applies — the three are set
     * independently in /admin/settings. It has no default on purpose: a new
     * payment flow has to state which one it is rather than quietly inheriting
     * another flow's rate.
     *
     * @return array{channel: string, label: string, gateway_fee: float, gateway_fee_base: float, gateway_tax: float, tax_percent: float, service_fee: float, service_fee_unit: float, units: int, total: float, midtrans_payments: array<int, string>}
     */
    public function forChannel(string $channel, float $amount, string $audience, int $units = 1): array
    {
        $config = config("payment_fees.channels.{$channel}");
        if (! $config || ! $config['enabled']) {
            throw new PaymentException('Metode pembayaran tidak tersedia.');
        }

        return $this->compute($channel, $config, $amount, $audience, $units);
    }

    /**
     * Every enabled channel with its breakdown, for the channel picker.
     *
     * `$audience` picks the platform margin — see forChannel().
     *
     * @return list<array{channel: string, label: string, gateway_fee: float, gateway_fee_base: float, gateway_tax: float, tax_percent: float, service_fee: float, service_fee_unit: float, units: int, total: float, midtrans_payments: array<int, string>}>
     */
    public function allChannels(float $amount, string $audience, int $units = 1): array
    {
        return collect(config('payment_fees.channels'))
            ->filter(fn (array $config) => $config['enabled'])
            ->map(fn (array $config, string $key) => $this->compute($key, $config, $amount, $audience, $units))
            ->values()
            ->all();
    }

    /**
     * @param  array{label: string, fee_type: string, fee_value: float, tax_percent: float, midtrans_payments: array<int, string>}  $config
     * @return array{channel: string, label: string, gateway_fee: float, gateway_fee_base: float, gateway_tax: float, tax_percent: float, service_fee: float, service_fee_unit: float, units: int, total: float, midtrans_payments: array<int, string>}
     */
    private function compute(string $key, array $config, float $amount, string $audience, int $units = 1): array
    {
        // Per-channel switches (/admin/settings) sit on top of the per-channel
        // config — keyed by $key so VA and e-wallet toggle independently.
        // Gateway fee off means there is no base left to tax, so PPN goes
        // with it automatically rather than needing its own branch.
        $base = ! PlatformSettings::get("gateway_fee_enabled_{$key}") ? 0.0 : (
            $config['fee_type'] === 'percent'
                ? $amount * $config['fee_value'] / 100
                : (float) $config['fee_value']
        );
        $tax = PlatformSettings::get("ppn_enabled_{$key}") ? $base * $config['tax_percent'] / 100 : 0.0;
        $gatewayFee = $base + $tax;

        // The platform margin is charged per unit being bought, not per
        // checkout: three tickets in one basket cost three service fees. The
        // gateway's own fee is genuinely per transaction (one Midtrans call,
        // one bank charge) and is deliberately NOT multiplied here — the two
        // fees answer different questions and multiplying both would bill the
        // bank's flat charge several times over.
        $units = max(1, $units);
        $serviceFeeUnit = (float) PlatformSettings::get($audience);
        $serviceFee = $serviceFeeUnit * $units;

        return [
            'channel' => $key,
            'label' => $config['label'],
            // The taxed total is what gets stored and charged; the two parts
            // below exist only so the picker can show what the buyer is paying
            // for. A channel with `tax_percent: 0` reports 0 here and the UI
            // drops the line entirely.
            'gateway_fee' => $gatewayFee,
            'gateway_fee_base' => $base,
            'gateway_tax' => $tax,
            'tax_percent' => (float) $config['tax_percent'],
            'service_fee' => $serviceFee,
            // The per-unit rate and the multiplier travel alongside the total
            // so the picker can spell out "Rp 2.000 x 3 tiket" instead of
            // dividing them back apart and disagreeing on the rounding.
            'service_fee_unit' => $serviceFeeUnit,
            'units' => $units,
            'total' => $amount + $gatewayFee + $serviceFee,
            'midtrans_payments' => $config['midtrans_payments'],
        ];
    }
}
