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

foreach ([...$testDatabase, ...$testGateway] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';
