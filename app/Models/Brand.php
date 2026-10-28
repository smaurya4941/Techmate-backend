<?php

namespace App\Models;

use App\Models\Concerns\CleansUpFiles;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use CleansUpFiles, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name', 'slug', 'description', 'logo_path', 'website_url', 'is_active', 'sort_order',
    ];

    protected static function publicFileColumns(): array
    {
        return ['logo_path'];
    }

    protected static function booted(): void
    {
        static::saving(function (Brand $brand) {
            if (! self::isSafeUrl($brand->website_url)) {
                throw new \InvalidArgumentException('website_url must be an http(s) URL.');
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

    public static function isSafeUrl(?string $url): bool
    {
        if ($url === null || $url === '') {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return filter_var($url, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true);
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
