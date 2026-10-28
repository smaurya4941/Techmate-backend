<?php

namespace App\Models;

use App\Models\Concerns\CleansUpFiles;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use CleansUpFiles, HasFactory;

    /** @var list<string> */
    protected $fillable = ['product_id', 'image_path', 'alt_text', 'caption', 'sort_order'];

    protected static function publicFileColumns(): array
    {
        return ['image_path'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
