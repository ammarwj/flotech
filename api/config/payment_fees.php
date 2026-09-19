<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Buyer-paid gateway fees, per Midtrans channel
    |--------------------------------------------------------------------------
    |
    | The buyer picks a channel before checkout, so the fee is exact rather
    | than an average smeared across all channels. `tax_percent` is per
    | channel (not a single constant) because not every payment method is
    | guaranteed to carry the same tax treatment going forward.
    |
    | Deployed config, not `platform_settings` — these are contract numbers
    | that rarely change (mirrors config/wallet.php). Flipping `enabled` is
    | the entire deploy for turning a channel on.
    |
    */

    'channels' => [
        'va' => [
            'label' => 'Virtual Account',
            'enabled' => true,
            'fee_type' => 'flat', // flat|percent
            'fee_value' => 4000,
            'tax_percent' => 11,
            // Overridable per channel from /admin/settings (see
            // App\Services\PlatformSettings::definitions()) — turning
            // fee_enabled off zeroes tax_enabled's base with it, since tax is
            // a percentage of the fee. Other channels are untouched.
            'fee_enabled' => true,
            'tax_enabled' => true,
            'midtrans_payments' => ['bca_va', 'bni_va', 'bri_va', 'permata_va', 'other_va', 'echannel'],
        ],
        'ewallet' => [
            'label' => 'QRIS',
            'enabled' => true,
            'fee_type' => 'percent',
            'fee_value' => 0.7,
            'tax_percent' => 11,
            'fee_enabled' => true,
            'tax_enabled' => true,
            'midtrans_payments' => ['gopay', 'shopeepay', 'qris'],
        ],
        'retail' => [
            'label' => 'Gerai Retail',
            'enabled' => false,
            'fee_type' => 'flat',
            'fee_value' => 5000,
            'tax_percent' => 11,
            'fee_enabled' => true,
            'tax_enabled' => true,
            'midtrans_payments' => ['indomaret', 'alfamart'],
        ],
    ],

];
