<?php

namespace App\Models;

use App\Support\MediaCleanup;
use Database\Factories\ProductDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Storage model: the uploaded file always lives on the private disk under
 * `file_path`. Only when `is_public` is true is a copy placed at the same key
 * on the public disk, which is what MediaUrl (and so the public API) serves.
 * Un-publishing removes that copy, so a private document is never reachable
 * through the public storage link.
 */
class ProductDocument extends Model
{
    /** @use HasFactory<ProductDocumentFactory> */
    use HasFactory;

    public const TYPES = ['brochure', 'datasheet', 'manual', 'certificate', 'other'];

    public const PRIVATE_DISK = 'local';

    public const PUBLIC_DISK = 'public';

    /** @var list<string> */
    protected $fillable = [
        'product_id', 'title', 'document_type', 'file_path', 'original_filename',
        'mime_type', 'file_size_bytes', 'language_code', 'sort_order', 'is_public',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProductDocument $document) {
            if ($document->isDirty('file_path') && Storage::disk(self::PRIVATE_DISK)->exists($document->file_path)) {
                $disk = Storage::disk(self::PRIVATE_DISK);
                // Content-sniffed, never the client-supplied type.
                $document->mime_type = $disk->mimeType($document->file_path) ?: null;
                $document->file_size_bytes = $disk->size($document->file_path);
            }

            if ($document->original_filename !== null) {
                $document->original_filename = mb_substr(basename(str_replace('\\', '/', $document->original_filename)), 0, 255);
            }
        });

        static::saved(function (ProductDocument $document) {
            if ($document->wasChanged('file_path') && $document->getOriginal('file_path')) {
                self::purge($document->getOriginal('file_path'));
            }

            $document->syncPublicCopy();
        });

        static::deleted(fn (ProductDocument $document) => self::purge($document->file_path));
    }

    /** Copies to / removes from the public disk to match is_public. */
    public function syncPublicCopy(): void
    {
        $private = Storage::disk(self::PRIVATE_DISK);
        $public = Storage::disk(self::PUBLIC_DISK);

        if ($this->is_public && $private->exists($this->file_path)) {
            if (! $public->exists($this->file_path)) {
                $public->put($this->file_path, $private->get($this->file_path));
            }
        } else {
            MediaCleanup::delete(self::PUBLIC_DISK, $this->file_path);
        }
    }

    private static function purge(string $path): void
    {
        MediaCleanup::delete(self::PRIVATE_DISK, $path);
        MediaCleanup::delete(self::PUBLIC_DISK, $path);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'sort_order' => 'integer',
            'is_public' => 'boolean',
        ];
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
