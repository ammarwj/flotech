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
        // Master/reference data only — safe to run on production (`migrate --seed`).
        // All seeders here are idempotent (updateOrCreate/firstOrCreate).
        $this->call([
            // The catalog first: events can't validate without it.
            CatalogSeeder::class,
            FeatureDefinitionSeeder::class,
            PlanSeeder::class,
            TestimonialSeeder::class,
            FaqSeeder::class,
        ]);

        // Demo/trial data (demo users with password "password", demo org & events)
        // is intentionally NOT run by default — it would leak weak accounts and
        // fake transactions into production. For local dev/testing, run explicitly:
        //   php artisan db:seed --class=UserSeeder
        //   php artisan db:seed --class=DemoEventSeeder
    }
}
