<?php

namespace Tests;

use App\Support\SchemaChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

/**
 * Base class for schema tests. RefreshDatabase runs migrate:fresh on the first
 * test, so this refuses to run against anything but an in-memory SQLite or a
 * database whose name ends in "_test".
 */
abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private runRefreshDatabase;
    }

    protected function refreshDatabase(): void
    {
        self::assertDisposableDatabase();
        $this->runRefreshDatabase();
    }

    public static function assertDisposableDatabase(): void
    {
        $config = config('database.connections.'.config('database.default'));
        $name = (string) ($config['database'] ?? '');

        if (($config['driver'] ?? '') === 'sqlite' && ($name === ':memory:' || str_contains($name, 'test'))) {
            return;
        }

        if (! str_ends_with($name, '_test')) {
            throw new RuntimeException("Refusing to run destructive tests against database '{$name}'. Use a dedicated *_test database.");
        }
    }

    /** Skip tests that depend on database-enforced CHECK constraints. */
    protected function requireCheckConstraints(): void
    {
        if (! SchemaChecks::supported()) {
            $this->markTestSkipped('CHECK constraints require MySQL 8.0.16+/MariaDB 10.2+ (run with phpunit.mysql.xml).');
        }
    }
}
