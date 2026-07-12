<?php

namespace Tests;

use App\Services\Catalog;
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

        // The catalog is cached; a fresh database per test means a stale cache.
        Catalog::flush();
    }
}
