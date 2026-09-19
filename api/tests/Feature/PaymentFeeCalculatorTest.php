<?php

namespace Tests\Feature;

use App\Services\PaymentFeeCalculator;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The buyer-paid fee formula: gateway fee (flat or percent, plus its own tax)
 * plus the platform's separate service fee — compared channel by channel so a
 * formula mix-up between VA and e-wallet shows up as a wrong number, not a
 * missing test.
 */
class PaymentFeeCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_flat_channel_adds_flat_fee_plus_its_tax(): void
    {
        PlatformSettings::put(['service_fee_amount' => 0], null);
        PlatformSettings::flush();

        $breakdown = app(PaymentFeeCalculator::class)->forChannel('va', 100_000, PaymentFeeCalculator::AUDIENCE_PARTICIPANT);

        // config: flat 4000, tax 11% -> 4000 * 1.11
        $this->assertEqualsWithDelta(4440.0, $breakdown['gateway_fee'], 0.0001);
        $this->assertSame(0.0, $breakdown['service_fee']);
        $this->assertEqualsWithDelta(104_440.0, $breakdown['total'], 0.0001);
    }

    public function test_percent_channel_taxes_the_percentage_not_the_price(): void
    {
        PlatformSettings::put(['service_fee_amount' => 0], null);
        PlatformSettings::flush();

        $breakdown = app(PaymentFeeCalculator::class)->forChannel('ewallet', 100_000, PaymentFeeCalculator::AUDIENCE_PARTICIPANT);

        // config: 0.7% of 100_000 = 700, tax 11% -> 700 * 1.11
        $this->assertEqualsWithDelta(777.0, $breakdown['gateway_fee'], 0.0001);
        $this->assertEqualsWithDelta(100_777.0, $breakdown['total'], 0.0001);
    }

    public function test_service_fee_is_a_flat_amount_regardless_of_price(): void
    {
        PlatformSettings::put(['service_fee_amount' => 1500], null);
        PlatformSettings::flush();

        $small = app(PaymentFeeCalculator::class)->forChannel('va', 100_000, PaymentFeeCalculator::AUDIENCE_PARTICIPANT);
        $large = app(PaymentFeeCalculator::class)->forChannel('va', 5_000_000, PaymentFeeCalculator::AUDIENCE_PARTICIPANT);

        $this->assertEqualsWithDelta(4440.0, $small['gateway_fee'], 0.0001);
        $this->assertEqualsWithDelta(1500.0, $small['service_fee'], 0.0001);
        $this->assertEqualsWithDelta(105_940.0, $small['total'], 0.0001);

        // Same flat fee no matter the price — that's the point of it being flat.
        $this->assertEqualsWithDelta(1500.0, $large['service_fee'], 0.0001);
    }

    public function test_gateway_fee_toggle_off_zeroes_fee_and_tax_together(): void
    {
        PlatformSettings::put(['gateway_fee_enabled_va' => false], null);
        PlatformSettings::flush();

        $breakdown = app(PaymentFeeCalculator::class)->forChannel('va', 100_000, PaymentFeeCalculator::AUDIENCE_PARTICIPANT);

        $this->assertSame(0.0, $breakdown['gateway_fee_base']);
        $this->assertSame(0.0, $breakdown['gateway_tax']);
        $this->assertSame(0.0, $breakdown['gateway_fee']);
    }

    public function test_ppn_toggle_off_zeroes_tax_but_keeps_the_gateway_fee(): void
    {
        PlatformSettings::put(['ppn_enabled_va' => false], null);
        PlatformSettings::flush();

        $breakdown = app(PaymentFeeCalculator::class)->forChannel('va', 100_000, PaymentFeeCalculator::AUDIENCE_PARTICIPANT);

        $this->assertEqualsWithDelta(4000.0, $breakdown['gateway_fee_base'], 0.0001);
        $this->assertSame(0.0, $breakdown['gateway_tax']);
        $this->assertEqualsWithDelta(4000.0, $breakdown['gateway_fee'], 0.0001);
    }

    /**
     * The user's own example: VA off must not touch e-wallet. Comparing
     * against a fresh (no-override) e-wallet breakdown is what proves the
     * toggle is scoped per channel — asserting "VA is zero" alone would still
     * pass if the switch were secretly global again.
     */
    public function test_channel_toggles_are_independent_of_each_other(): void
    {
        $baseline = app(PaymentFeeCalculator::class)->forChannel('ewallet', 100_000, PaymentFeeCalculator::AUDIENCE_PARTICIPANT);

        PlatformSettings::put([
            'gateway_fee_enabled_va' => false,
            'ppn_enabled_va' => false,
        ], null);
        PlatformSettings::flush();

        $va = app(PaymentFeeCalculator::class)->forChannel('va', 100_000, PaymentFeeCalculator::AUDIENCE_PARTICIPANT);
        $ewallet = app(PaymentFeeCalculator::class)->forChannel('ewallet', 100_000, PaymentFeeCalculator::AUDIENCE_PARTICIPANT);

        $this->assertSame(0.0, $va['gateway_fee']);
        $this->assertEqualsWithDelta($baseline['gateway_fee'], $ewallet['gateway_fee'], 0.0001);
        $this->assertEqualsWithDelta($baseline['gateway_fee_base'], $ewallet['gateway_fee_base'], 0.0001);
        $this->assertEqualsWithDelta($baseline['gateway_tax'], $ewallet['gateway_tax'], 0.0001);
    }

    /**
     * The picker shows the fee and its tax as separate lines, so the split has
     * to add back up to the number that is actually stored and charged — and a
     * channel configured without tax has to report a 0 the UI can drop, not a
     * missing key.
     */
    public function test_tax_is_reported_separately_and_sums_back_to_the_gateway_fee(): void
    {
        $calculator = app(PaymentFeeCalculator::class);

        $taxed = $calculator->forChannel('va', 100_000, PaymentFeeCalculator::AUDIENCE_PARTICIPANT);
        $this->assertEqualsWithDelta(4000.0, $taxed['gateway_fee_base'], 0.0001);
        $this->assertEqualsWithDelta(440.0, $taxed['gateway_tax'], 0.0001);
        $this->assertEqualsWithDelta(
            $taxed['gateway_fee'],
            $taxed['gateway_fee_base'] + $taxed['gateway_tax'],
            0.0001
        );

        config()->set('payment_fees.channels.va.tax_percent', 0);
        $untaxed = $calculator->forChannel('va', 100_000, PaymentFeeCalculator::AUDIENCE_PARTICIPANT);

        $this->assertSame(0.0, $untaxed['gateway_tax']);
        $this->assertEqualsWithDelta(4000.0, $untaxed['gateway_fee'], 0.0001);
    }

    /**
     * Two margins, one formula. Compared on the same channel and the same
     * amount so nothing else can explain the difference — asserting one
     * audience alone would still pass if both read the same key.
     */
    public function test_each_audience_reads_its_own_platform_margin(): void
    {
        PlatformSettings::put([
            'service_fee_amount' => 2000,
            'plan_service_fee_amount' => 5000,
        ], null);
        PlatformSettings::flush();

        $calculator = app(PaymentFeeCalculator::class);

        $participant = $calculator->forChannel('va', 100_000, PaymentFeeCalculator::AUDIENCE_PARTICIPANT);
        $organizer = $calculator->forChannel('va', 100_000, PaymentFeeCalculator::AUDIENCE_ORGANIZER);

        $this->assertEqualsWithDelta(2000.0, $participant['service_fee'], 0.0001);
        $this->assertEqualsWithDelta(5000.0, $organizer['service_fee'], 0.0001);

        // The gateway's own fee is the bank's, not ours — it cannot differ
        // between the two.
        $this->assertEqualsWithDelta(
            $participant['gateway_fee'],
            $organizer['gateway_fee'],
            0.0001,
        );
    }

    public function test_disabled_channel_is_rejected(): void
    {
        $this->expectException(\App\Exceptions\PaymentException::class);

        app(PaymentFeeCalculator::class)->forChannel('retail', 100_000, PaymentFeeCalculator::AUDIENCE_PARTICIPANT);
    }

    public function test_unknown_channel_is_rejected(): void
    {
        $this->expectException(\App\Exceptions\PaymentException::class);

        app(PaymentFeeCalculator::class)->forChannel('bogus', 100_000, PaymentFeeCalculator::AUDIENCE_PARTICIPANT);
    }

    public function test_all_channels_returns_only_enabled_ones(): void
    {
        $channels = collect(app(PaymentFeeCalculator::class)->allChannels(100_000, PaymentFeeCalculator::AUDIENCE_PARTICIPANT))
            ->pluck('channel');

        $this->assertTrue($channels->contains('va'));
        $this->assertTrue($channels->contains('ewallet'));
        $this->assertFalse($channels->contains('retail'));
    }

    public function test_no_rounding_anywhere_in_the_chain(): void
    {
        PlatformSettings::put(['service_fee_amount' => 1333.33], null);
        PlatformSettings::flush();

        $breakdown = app(PaymentFeeCalculator::class)->forChannel('ewallet', 33_333, PaymentFeeCalculator::AUDIENCE_PARTICIPANT);

        // A price/percent combo picked to produce a long decimal tail; a
        // round() slipped in anywhere collapses it to a clean number.
        $expectedGateway = (33_333 * 0.007) * 1.11;

        $this->assertEqualsWithDelta($expectedGateway, $breakdown['gateway_fee'], 1e-9);
        // Flat amount, stored as-is: still not a whole number if configured that way.
        $this->assertEqualsWithDelta(1333.33, $breakdown['service_fee'], 1e-9);
        // A round() slipped in anywhere would collapse this to whole cents.
        $this->assertNotEquals($breakdown['gateway_fee'] * 100, round($breakdown['gateway_fee'] * 100));
    }
}
