<?php

namespace Tests\Feature\Database;

use App\Support\SchemaChecks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\DatabaseTestCase;
use Tests\TestCase;

/**
 * Rolls the Phase 3 migrations back and forward again on the disposable test
 * database. DDL implicitly commits on MySQL, so this does not use the
 * transaction wrapper and restores the schema in a finally block.
 */
class MigrationRollbackTest extends TestCase
{
    private const PHASE3_TABLES = [
        'categories', 'brands', 'products', 'product_images', 'product_specifications',
        'product_documents', 'leads', 'lead_products', 'lead_activities', 'follow_ups',
        'quotations', 'quotation_items', 'pages', 'settings',
        // Phase 4 addition, rolled back with the rest.
        'distributor_applications',
    ];

    public function test_phase_three_migrations_roll_back_and_re_apply_cleanly(): void
    {
        DatabaseTestCase::assertDisposableDatabase();

        if (! SchemaChecks::supported()) {
            $this->markTestSkipped('Rollback is verified on MySQL/MariaDB only (run with phpunit.mysql.xml).');
        }

        Artisan::call('migrate', ['--force' => true]);

        try {
            foreach (self::PHASE3_TABLES as $table) {
                $this->assertTrue(Schema::hasTable($table), "{$table} missing after migrate");
            }

            $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 18, '--force' => true]));

            foreach (self::PHASE3_TABLES as $table) {
                $this->assertFalse(Schema::hasTable($table), "{$table} still present after rollback");
            }
            $this->assertTrue(Schema::hasTable('users'));
            $this->assertFalse(Schema::hasColumn('users', 'role'));
        } finally {
            Artisan::call('migrate', ['--force' => true]);
        }

        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertTrue(Schema::hasTable('quotation_items'));
    }
}
