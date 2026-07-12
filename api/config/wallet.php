<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Organizer wallet & payouts
    |--------------------------------------------------------------------------
    |
    | Buyers pay the platform's single Midtrans merchant account, so an
    | organizer's share is held in a wallet and remitted by bank transfer.
    | These rules are deployed (not editable from the admin UI) and every
    | withdrawal snapshots the values it was created under.
    |
    */

    'minimum_withdrawal' => (float) env('WALLET_MIN_WITHDRAWAL', 100000),

    'admin_fee' => (float) env('WALLET_ADMIN_FEE', 5000),

    // Extra cooling period after an event ends before its funds are released.
    'hold_days' => (int) env('WALLET_HOLD_DAYS', 0),

    // `events.end_date` is a plain date. It means "end of that day" in this
    // zone — not in UTC, which would release funds mid-event at 07:00 WIB.
    'timezone' => env('WALLET_TIMEZONE', 'Asia/Jakarta'),

];
