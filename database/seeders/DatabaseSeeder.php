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
     * Production seeding is intentionally empty: no catalogue, customer or
     * company data is invented. Sample data is limited to local/testing.
     */
    public function run(): void
    {
        if (app()->environment(['local', 'testing'])) {
            $this->call(DevelopmentSampleSeeder::class);
        }
    }
}
