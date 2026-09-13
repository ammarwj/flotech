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
    /** Participant paying an organizer: tickets and registration fees. */
    public const AUDIENCE_PARTICIPANT = 'service_fee_percent';

    /** Organizer paying the platform: event plan purchases and upgrades. */
    public const AUDIENCE_ORGANIZER = 'plan_service_fee_percent';

    /**
     * Breakdown for one channel. Throws when the channel is unknown or has
     * been disabled (e.g. `retail` before it's turned on).
     *
     * `$audience` picks which platform margin applies — the two are set
     * independently in /admin/settings. It has no default on purpose: a new
     * payment flow has to state which side of the platform it sits on rather
     * than quietly inheriting the other side's rate.
     *
     * @return array{channel: string, label: string, gateway_fee: float, gateway_fee_base: float, gateway_tax: float, tax_percent: float, service_fee: float, total: float, midtrans_payments: array<int, string>}
     */
    public function forChannel(string $channel, float $amount, string $audience): array
    {
        $config = config("payment_fees.channels.{$channel}");
        if (! $config || ! $config['enabled']) {
            throw new PaymentException('Metode pembayaran tidak tersedia.');
        }

        return $this->compute($channel, $config, $amount, $audience);
    }

    /**
     * Every enabled channel with its breakdown, for the channel picker.
     *
     * `$audience` picks the platform margin — see forChannel().
     *
     * @return list<array{channel: string, label: string, gateway_fee: float, gateway_fee_base: float, gateway_tax: float, tax_percent: float, service_fee: float, total: float, midtrans_payments: array<int, string>}>
     */
    public function allChannels(float $amount, string $audience): array
    {
        return collect(config('payment_fees.channels'))
            ->filter(fn (array $config) => $config['enabled'])
            ->map(fn (array $config, string $key) => $this->compute($key, $config, $amount, $audience))
            ->values()
            ->all();
    }

    /**
     * @param  array{label: string, fee_type: string, fee_value: float, tax_percent: float, midtrans_payments: array<int, string>}  $config
     * @return array{channel: string, label: string, gateway_fee: float, gateway_fee_base: float, gateway_tax: float, tax_percent: float, service_fee: float, total: float, midtrans_payments: array<int, string>}
     */
    private function compute(string $key, array $config, float $amount, string $audience): array
    {
        $base = $config['fee_type'] === 'percent'
            ? $amount * $config['fee_value'] / 100
            : (float) $config['fee_value'];
        $tax = $base * $config['tax_percent'] / 100;
        $gatewayFee = $base + $tax;
        $serviceFee = $amount * PlatformSettings::get($audience) / 100;

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
            'total' => $amount + $gatewayFee + $serviceFee,
            'midtrans_payments' => $config['midtrans_payments'],
        ];
    }
}
