<?php

namespace App\Models;

use Database\Factories\LeadProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadProduct extends Model
{
    /** @use HasFactory<LeadProductFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'lead_id', 'product_id', 'product_name_snapshot', 'product_code_snapshot',
        'quantity', 'customer_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
