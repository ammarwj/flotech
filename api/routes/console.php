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

// Manual transfers get no Midtrans expiry webhook, so abandoned orders would
// hold their ticket quota forever.
Schedule::command('tickets:expire-manual')->hourly()->withoutOverlapping();

// The visitor dedup ledger is only useful on its own day; the daily roll-up it
// feeds (event_view_daily) is kept forever.
Schedule::command('views:prune')->dailyAt('02:00')->withoutOverlapping();
