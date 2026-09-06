<?php

namespace Tests;

use App\Services\Catalog;
use App\Services\DomainService;
use App\Services\PlanGate;
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

        // All four are cached; a fresh database per test would otherwise be read
        // through the previous test's cache.
        Catalog::flush();
        PlatformSettings::flush();
        PlanGate::flush();
        DomainService::flush();
    }
}
