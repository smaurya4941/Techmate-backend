<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Adds named CHECK constraints on MySQL 8.0.16+ / MariaDB 10.2+.
 *
 * The Laravel schema builder has no CHECK support. On other drivers (the
 * SQLite default test connection) calls are a no-op; the same rules are then
 * enforced by the application layer, as documented in docs/database-schema.md.
 */
class SchemaChecks
{
    public static function supported(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    public static function add(string $table, string $name, string $expression): void
    {
        if (! self::supported()) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_{$name}` CHECK ({$expression})");
    }

    /**
     * @param  list<string>  $values
     */
    public static function in(string $table, string $column, array $values): void
    {
        $list = implode(', ', array_map(fn (string $v) => "'{$v}'", $values));

        self::add($table, "{$column}_valid", "`{$column}` IN ({$list})");
    }
}
