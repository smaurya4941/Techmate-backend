<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Turns a stored media path into a public URL (public disk), leaving absolute
 * http(s) URLs untouched so CDN-hosted assets keep working.
 */
class MediaUrl
{
    public static function for(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return Storage::disk('public')->url(ltrim($path, '/'));
    }
}
