<?php

namespace App\Support;

/**
 * Generates non-sequential public identifiers (lead references, quotation numbers).
 *
 * Uniqueness is ultimately guaranteed by the database UNIQUE index; callers
 * check for an existing value and regenerate on a (very unlikely) collision.
 */
class ReferenceGenerator
{
    // No 0/O/1/I to keep references readable over the phone.
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function random(int $length): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * @param  callable(string): bool  $exists
     */
    public static function unique(string $prefix, int $length, callable $exists): string
    {
        do {
            $candidate = $prefix.self::random($length);
        } while ($exists($candidate));

        return $candidate;
    }
}
