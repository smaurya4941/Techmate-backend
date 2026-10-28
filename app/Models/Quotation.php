<?php

namespace App\Models;

use App\Support\ReferenceGenerator;
use Database\Factories\QuotationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    /** @use HasFactory<QuotationFactory> */
    use HasFactory;

    public const STATUSES = ['draft', 'sent', 'accepted', 'rejected', 'expired', 'cancelled'];

    /** Permitted status transitions (current => allowed next statuses). */
    public const TRANSITIONS = [
        'draft' => ['sent', 'cancelled'],
        'sent' => ['accepted', 'rejected', 'expired', 'cancelled'],
        'accepted' => [],
        'rejected' => [],
        'expired' => [],
        'cancelled' => [],
    ];

    /**
     * Staff-only model. Status, totals and workflow timestamps are written by
     * trusted domain code inside a transaction, never mass-assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lead_id', 'currency_code', 'issue_date', 'valid_until',
        'customer_name_snapshot', 'company_name_snapshot', 'billing_address_snapshot',
        'customer_email_snapshot', 'customer_phone_snapshot', 'terms_and_conditions', 'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (Quotation $quotation) {
            $quotation->quotation_number ??= ReferenceGenerator::unique(
                'QT-'.now()->format('Ym').'-', 6,
                fn (string $number) => static::where('quotation_number', $number)->exists()
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public function nextStatuses(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
