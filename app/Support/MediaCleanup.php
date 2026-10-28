<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes a stored file only when nothing in the database still references it
 * (the product gallery can share a path with the product's primary image).
 */
class MediaCleanup
{
    /** Every column that stores a path on the public disk. */
    private const PUBLIC_REFERENCES = [
        'products' => 'primary_image_path',
        'product_images' => 'image_path',
        'categories' => 'image_path',
        'brands' => 'logo_path',
    ];

    public static function deletePublic(?string $path): void
    {
        if (blank($path) || preg_match('#^https?://#i', $path)) {
            return;
        }

        foreach (self::PUBLIC_REFERENCES as $table => $column) {
            if (DB::table($table)->where($column, $path)->exists()) {
                return;
            }
        }

        self::delete('public', $path);
    }

    public static function delete(string $disk, ?string $path): void
    {
        if (blank($path) || preg_match('#^https?://#i', $path)) {
            return;
        }

        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $e) {
            // Never fail the admin operation over an orphan; surface it for operators.
            Log::warning('Failed to delete stored file.', ['disk' => $disk, 'path' => $path, 'exception' => $e::class]);
        }
    }
}
