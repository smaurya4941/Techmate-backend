<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Sequential, human-readable references (TMI-2026-000001, QT-2026-000001, ORD-2026-000001).
 *
 * The counter row is incremented under a row lock inside the caller's transaction, so
 * concurrent requests serialise on it and never receive the same number. If the caller's
 * transaction rolls back, the increment rolls back with it (no gaps from failed saves).
 * Each reference also sits behind a UNIQUE index as the final guarantee.
 */
final class ReferenceNumbers
{
    public static function lead(): string
    {
        return self::format('TMI', 'lead');
    }

    public static function quotationSeries(): string
    {
        return self::format('QT', 'quotation');
    }

    public static function order(): string
    {
        return self::format('ORD', 'order');
    }

    public static function next(string $sequence): int
    {
        return DB::transaction(function () use ($sequence) {
            DB::table('sequences')->insertOrIgnore(['name' => $sequence, 'value' => 0, 'created_at' => now(), 'updated_at' => now()]);

            $current = (int) DB::table('sequences')->where('name', $sequence)->lockForUpdate()->value('value');
            DB::table('sequences')->where('name', $sequence)->update(['value' => $current + 1, 'updated_at' => now()]);

            return $current + 1;
        });
    }

    private static function format(string $prefix, string $sequence): string
    {
        $year = now()->utc()->format('Y');

        return sprintf('%s-%s-%06d', $prefix, $year, self::next("{$sequence}:{$year}"));
    }
}
