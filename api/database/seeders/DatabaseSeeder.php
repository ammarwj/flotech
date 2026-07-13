<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // The catalog first: events can't validate without it.
            CatalogSeeder::class,
            FeatureDefinitionSeeder::class,
            PlanSeeder::class,
            UserSeeder::class,
            DemoEventSeeder::class,
        ]);
    }
}
