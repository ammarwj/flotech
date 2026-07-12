<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Everything the app needs before an event can exist: the sports and the
 * reference options. Tests seed this too — validation reads it.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SportSeeder::class,
            ConfigOptionSeeder::class,
        ]);
    }
}
