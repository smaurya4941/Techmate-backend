<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Model events stay enabled on purpose: reference/number generation and
     * category/spec validation run in model hooks.
     *
     * The real catalogue (categories, authorised brands, first products) is seeded outside
     * the test suite and is safe to re-run. Invented sample data is limited to local/testing.
     */
    public function run(): void
    {
        if (! app()->environment('testing')) {
            $this->call(RealCatalogueSeeder::class);
        }

        if (app()->environment(['local', 'testing'])) {
            $this->call(DevelopmentSampleSeeder::class);
        }
    }
}
