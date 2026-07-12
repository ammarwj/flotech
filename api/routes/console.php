<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Wallet: held funds become withdrawable once an event is over, and the
// denormalized balances are checked against the ledger daily.
Schedule::command('wallet:release')->hourly()->withoutOverlapping();
Schedule::command('wallet:audit')->dailyAt('01:00');
