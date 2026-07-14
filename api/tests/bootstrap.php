<?php

/**
 * The container exports DB_CONNECTION/DB_DATABASE for the *dev* database, and
 * those real env vars win over phpunit.xml's <env> entries. Left alone, the
 * suite would run against the developer's database and RefreshDatabase would
 * wipe it. Pin the test connection here, before the app boots.
 */
$testDatabase = [
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'DB_HOST' => '',
    'DB_PORT' => '',
    'DB_USERNAME' => '',
    'DB_PASSWORD' => '',
];

/**
 * Same story for the gateway: .env carries real sandbox keys, so the suite was
 * trying to reach Midtrans over HTTP and payments never settled. Blanking the
 * key puts MidtransService in mock mode, which is what the tests assume ("no
 * credentials in tests → the fee settles immediately").
 */
$testGateway = [
    'MIDTRANS_SERVER_KEY' => '',
    'MIDTRANS_CLIENT_KEY' => '',
    'MIDTRANS_IS_PRODUCTION' => 'false',
];

/**
 * And the same story once more for the drivers. The container exports
 * QUEUE_CONNECTION=redis, so every job the suite dispatched was pushed onto the
 * dev queue and never ran — a queued side effect could not be asserted at all
 * (ReleaseEventFundsJob was the one that caught it: marking an event finished
 * released nothing, because nothing executed). `sync` runs jobs inline, which is
 * what phpunit.xml already intends and every test assumes.
 *
 * Cache/session/mail follow for isolation: pointed at the container's redis they
 * leak state between the suite and the running dev app.
 */
$testServices = [
    'QUEUE_CONNECTION' => 'sync',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null',
];

foreach ([...$testDatabase, ...$testGateway, ...$testServices] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';
