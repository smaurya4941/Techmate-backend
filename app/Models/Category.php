<?php

namespace App\Models;

use App\Models\Concerns\CleansUpFiles;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use CleansUpFiles, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'parent_id', 'name', 'slug', 'description', 'image_path',
        'meta_title', 'meta_description', 'is_active', 'sort_order',
    ];

    protected static function publicFileColumns(): array
    {
        return ['image_path'];
    }

    protected static function booted(): void
    {
        static::saving(function (Category $category) {
            if ($category->exists && $category->isDirty('parent_id')) {
                $category->assertValidParent();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /**
     * A category cannot be its own parent or sit beneath one of its descendants.
     * (A CHECK constraint cannot express this on an AUTO_INCREMENT key.)
     */
    public function assertValidParent(): void
    {
        $parentId = $this->parent_id;

        for ($depth = 0; $parentId !== null && $depth < 50; $depth++) {
            if ($parentId === $this->id) {
                throw new \InvalidArgumentException('A category cannot be its own ancestor.');
            }
            $parentId = static::withTrashed()->whereKey($parentId)->value('parent_id');
        }
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
