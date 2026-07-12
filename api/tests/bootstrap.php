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

foreach ($testDatabase as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';
