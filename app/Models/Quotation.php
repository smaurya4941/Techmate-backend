<?php

namespace App\Models;

use App\Support\ReferenceNumbers;
use Database\Factories\QuotationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    /** @use HasFactory<QuotationFactory> */
    use HasFactory;

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'sent', 'accepted', 'rejected', 'expired', 'superseded', 'cancelled'];

    public const ACCEPTANCE_METHODS = [
        'signed_acceptance' => 'Signed acceptance',
        'purchase_order' => 'Customer purchase order',
        'email_confirmation' => 'Written e-mail confirmation',
        'advance_payment' => 'Advance payment received',
    ];

    /**
     * Permitted status transitions (current => allowed next statuses). Content can only be
     * edited while draft; everything after approval is an immutable commercial record.
     */
    public const TRANSITIONS = [
        'draft' => ['pending_approval', 'cancelled'],
        'pending_approval' => ['approved', 'draft', 'cancelled'],
        'approved' => ['sent', 'superseded', 'cancelled'],
        'sent' => ['accepted', 'rejected', 'expired', 'superseded', 'cancelled'],
        'accepted' => [],
        'rejected' => [],
        'expired' => [],
        'superseded' => [],
        'cancelled' => [],
    ];

    /** Statuses a revision may be created from (the accepted version is never revised). */
    public const REVISABLE = ['approved', 'sent', 'rejected', 'expired'];

    /**
     * Staff-only model. Status, totals and workflow timestamps are written by
     * trusted domain code inside a transaction, never mass-assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lead_id', 'currency_code', 'issue_date', 'valid_until',
        'customer_name_snapshot', 'company_name_snapshot', 'billing_address_snapshot',
        'customer_email_snapshot', 'customer_phone_snapshot', 'terms_and_conditions',
        'payment_terms', 'delivery_terms', 'warranty_terms', 'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (Quotation $quotation) {
            $quotation->series_number ??= ReferenceNumbers::quotationSeries();
            $quotation->version ??= 1;
            $quotation->quotation_number ??= $quotation->series_number.'-V'.$quotation->version;
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
            'version' => 'integer',
            'is_current' => 'boolean',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'freight_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
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

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function canBeRevised(): bool
    {
        return $this->is_current && in_array($this->status, self::REVISABLE, true);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(QuotationEvent::class)->orderBy('occurred_at')->orderBy('id');
    }
}
