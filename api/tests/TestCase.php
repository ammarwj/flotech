<?php

namespace Tests;

use App\Services\Catalog;
use App\Services\PlatformSettings;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sports, formats, tiebreakers and sponsor tiers now live in the database,
     * and validation reads them — an event can't even be created without them.
     * So every test starts from the seeded catalog.
     */
    protected bool $seed = true;

    protected string $seeder = CatalogSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Both are cached (and memoized in a static); a fresh database per test
        // would otherwise be read through the previous test's cache.
        Catalog::flush();
        PlatformSettings::flush();
    }
}
