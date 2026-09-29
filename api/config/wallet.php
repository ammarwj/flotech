<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Organizer wallet & payouts
    |--------------------------------------------------------------------------
    |
    | Buyers pay the platform's single Midtrans merchant account, so an
    | organizer's share is held in a wallet and remitted by bank transfer.
    | These are the DEFAULTS; a super admin overrides them at /admin/settings
    | (see PlatformSettings) and every withdrawal snapshots the values it was
    | created under. `timezone` is the exception — it is deployed only.
    |
    */

    'minimum_withdrawal' => (float) env('WALLET_MIN_WITHDRAWAL', 100000),

    'admin_fee' => (float) env('WALLET_ADMIN_FEE', 5000),

    // Extra days on top of the 01:00 boundary: 0 releases a credit at the next
    // 01:00, 1 holds it one more day. Not tied to the event's end date any more.
    'hold_days' => (int) env('WALLET_HOLD_DAYS', 0),

    // The zone the 01:00 release boundary is read in. The app runs in UTC, so
    // computing it there would move the cut-off by seven hours.
    'timezone' => env('WALLET_TIMEZONE', 'Asia/Jakarta'),

];
