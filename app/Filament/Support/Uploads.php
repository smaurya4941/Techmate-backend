<?php

namespace App\Filament\Support;

use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Pre-configured upload fields. Storage names are random and take their
 * extension from the file's sniffed content type, never from the client-supplied
 * name, and the allowed MIME types are enforced by server-side validation.
 * SVG is deliberately excluded (it can carry script).
 */
class Uploads
{
    public const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const DOCUMENT_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const STORAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];

    public static function image(string $name, string $directory, int $maxKilobytes = 3072): FileUpload
    {
        return FileUpload::make($name)
            ->disk('public')
            ->visibility('public')
            ->directory($directory)
            ->image()
            ->acceptedFileTypes(self::IMAGE_MIME_TYPES)
            ->maxSize($maxKilobytes)
            ->imageEditor()
            ->preventFilePathTampering()
            ->getUploadedFileNameForStorageUsing(self::safeName(...))
            ->helperText('JPEG, PNG or WebP, up to '.round($maxKilobytes / 1024).' MB.');
    }

    /** Documents are stored privately; ProductDocument publishes a copy only when approved. */
    public static function document(string $name, string $directory, int $maxKilobytes = 20480): FileUpload
    {
        return FileUpload::make($name)
            ->disk('local')
            ->visibility('private')
            ->directory($directory)
            ->acceptedFileTypes(self::DOCUMENT_MIME_TYPES)
            ->maxSize($maxKilobytes)
            ->preventFilePathTampering()
            ->storeFileNamesIn('original_filename')
            ->getUploadedFileNameForStorageUsing(self::safeName(...))
            ->downloadable(false)
            ->openable(false)
            ->helperText('PDF, Word or Excel, up to '.round($maxKilobytes / 1024).' MB. Stored privately until marked public.');
    }

    public static function safeName(TemporaryUploadedFile $file): string
    {
        // Defence in depth: even if validation were bypassed, nothing but a known
        // document/image extension is ever written (never .php, .html, .svg ...).
        $extension = strtolower((string) $file->guessExtension());
        $extension = in_array($extension, self::STORAGE_EXTENSIONS, true) ? $extension : 'bin';

        return strtolower((string) Str::ulid()).'.'.$extension;
    }
}
