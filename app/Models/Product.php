<?php

namespace App\Models;

use App\Models\Concerns\CleansUpFiles;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use CleansUpFiles, HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    /**
     * status, published_at, created_by and updated_by are not mass-assignable;
     * use publish()/archive() and set the audit columns in trusted code.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category_id', 'brand_id', 'name', 'slug', 'sku', 'short_description',
        'description', 'price', 'currency_code', 'primary_image_path',
        'is_featured', 'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    protected static function publicFileColumns(): array
    {
        return ['primary_image_path'];
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            if ($userId = auth()->id()) {
                $product->updated_by = $userId;
                $product->created_by ??= $userId;
            }
        });
    }

    public function publish(): static
    {
        $this->status = self::STATUS_PUBLISHED;
        $this->published_at ??= now();

        return $this;
    }

    public function archive(): static
    {
        $this->status = self::STATUS_ARCHIVED;

        return $this;
    }

    /**
     * Publicly visible: published, publish date reached, and in an active category.
     * Soft-deleted rows are already excluded by the SoftDeletes scope.
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            // The category relation includes trashed rows, so exclude them explicitly.
            ->whereHas('category', fn (Builder $q) => $q->withoutTrashed()->where('is_active', true));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function specifications(): HasMany
    {
        return $this->hasMany(ProductSpecification::class)->orderBy('sort_order')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProductDocument::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Only documents flagged public; use this for any public catalogue response. */
    public function publicDocuments(): HasMany
    {
        return $this->documents()->where('is_public', true);
    }
}
