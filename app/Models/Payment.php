<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One received payment. Rows are never deleted: a wrong entry is voided with a reason
 * so the ledger and its audit trail stay intact. Status moves only via OrderService.
 */
class Payment extends Model
{
    public const METHODS = [
        'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'cash' => 'Cash',
        'upi' => 'UPI', 'card' => 'Card', 'other' => 'Other',
    ];

    public const STATUSES = ['recorded', 'verified', 'voided'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Payments cannot be deleted; void them instead.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
            'verified_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
