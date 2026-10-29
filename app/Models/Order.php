<?php

namespace App\Models;

use App\Support\ReferenceNumbers;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A confirmed commercial deal. Commercial fields are a snapshot of the accepted
 * quotation and are never recalculated. status tracks fulfilment only; money owed
 * and received is derived from the payments ledger (see amountReceived()).
 */
class Order extends Model
{
    public const STATUSES = ['pending_confirmation', 'confirmed', 'processing', 'ready_for_dispatch', 'dispatched', 'delivered', 'completed', 'cancelled'];

    public const TRANSITIONS = [
        'pending_confirmation' => ['confirmed', 'cancelled'],
        'confirmed' => ['processing', 'cancelled'],
        'processing' => ['ready_for_dispatch', 'cancelled'],
        'ready_for_dispatch' => ['dispatched', 'cancelled'],
        'dispatched' => ['delivered'],
        'delivered' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    /** Trusted code only; nothing here is mass-assignable from a request. */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->order_number ??= ReferenceNumbers::order();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'freight_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'installation_required' => 'boolean',
            'installation_completed_at' => 'datetime',
            'ordered_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** Sum of every payment that has not been voided. */
    public function amountReceived(): string
    {
        return $this->sumPayments(['recorded', 'verified']);
    }

    public function amountVerified(): string
    {
        return $this->sumPayments(['verified']);
    }

    /** Outstanding = order total - payments received (never below zero in display). */
    public function balanceDue(): string
    {
        return (string) BigDecimal::of((string) $this->grand_total)->minus($this->amountReceived())->toScale(2);
    }

    /** unpaid | partial | paid (verified in full) | awaiting_verification (fully received, not fully verified) */
    public function paymentStatus(): string
    {
        $total = BigDecimal::of((string) $this->grand_total);
        $received = BigDecimal::of($this->amountReceived());

        if ($received->isZero()) {
            return 'unpaid';
        }

        if ($received->isLessThan($total)) {
            return 'partial';
        }

        return BigDecimal::of($this->amountVerified())->isGreaterThanOrEqualTo($total) ? 'paid' : 'awaiting_verification';
    }

    /** @param  list<string>  $statuses */
    private function sumPayments(array $statuses): string
    {
        $sum = BigDecimal::zero()->toScale(2);
        $payments = $this->relationLoaded('payments') ? $this->payments : $this->payments()->get();

        foreach ($payments as $payment) {
            if (in_array($payment->status, $statuses, true)) {
                $sum = $sum->plus((string) $payment->amount);
            }
        }

        return (string) $sum;
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_at')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
