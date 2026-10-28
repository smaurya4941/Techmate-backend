<?php

namespace App\Models;

use Database\Factories\ProductSpecificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSpecification extends Model
{
    /** @use HasFactory<ProductSpecificationFactory> */
    use HasFactory;

    public const TYPE_STRING = 'string';

    public const TYPE_NUMBER = 'number';

    public const TYPE_BOOLEAN = 'boolean';

    public const VALUE_TYPES = [self::TYPE_STRING, self::TYPE_NUMBER, self::TYPE_BOOLEAN];

    private const VALUE_COLUMNS = [
        self::TYPE_STRING => 'value_string',
        self::TYPE_NUMBER => 'value_number',
        self::TYPE_BOOLEAN => 'value_boolean',
    ];

    /** @var list<string> */
    protected $fillable = [
        'product_id', 'specification_group', 'specification_key', 'specification_label',
        'value_type', 'value_string', 'value_number', 'value_boolean', 'unit', 'sort_order',
    ];

    protected static function booted(): void
    {
        // Mirrors the MySQL CHECK so the rule also holds on drivers without CHECK support.
        static::saving(function (ProductSpecification $spec) {
            $expected = self::VALUE_COLUMNS[$spec->value_type] ?? null;
            if ($expected === null) {
                throw new \InvalidArgumentException("Unknown value_type '{$spec->value_type}'.");
            }

            foreach (self::VALUE_COLUMNS as $column) {
                $isNull = $spec->getAttribute($column) === null;
                if (($column === $expected) === $isNull) {
                    throw new \InvalidArgumentException("Only {$expected} may be populated for value_type '{$spec->value_type}'.");
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_number' => 'decimal:4',
            'value_boolean' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** The typed value, selected by value_type. */
    public function value(): string|bool|null
    {
        return $this->getAttribute(self::VALUE_COLUMNS[$this->value_type]);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
