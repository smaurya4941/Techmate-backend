<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Support\AuditLog;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Orders, fulfilment and the payment ledger. Sales closure, payment and delivery are
 * independent: advancing an order never touches payments, and recording a payment
 * never changes the fulfilment status. Outstanding balance is always derived.
 */
class OrderService
{
    /**
     * Creates the order for a won lead from its accepted quotation, or returns the existing
     * one. Safe to call twice: unique(lead_id) and unique(quotation_id) are the backstop.
     * Runs inside the caller's transaction (LeadOutcomeService::markWon).
     *
     * @param  array{delivery_address?: ?string, installation_required?: bool, notes?: ?string}  $options
     */
    public function createFromQuotation(Lead $lead, Quotation $quotation, User $actor, array $options = []): Order
    {
        if ($existing = Order::query()->where('lead_id', $lead->id)->first()) {
            return $existing;
        }

        if ($quotation->lead_id !== $lead->id || $quotation->status !== 'accepted' || ! $quotation->is_current) {
            throw ValidationException::withMessages(['quotation' => 'An order can only be created from the current, accepted quotation of this lead.']);
        }

        try {
            return DB::transaction(function () use ($lead, $quotation, $actor, $options) {
                $order = new Order;
                $order->forceFill([
                    'lead_id' => $lead->id,
                    'quotation_id' => $quotation->id,
                    'customer_id' => $lead->customer_id,
                    'status' => 'pending_confirmation',
                    'currency_code' => $quotation->currency_code,
                    'customer_name_snapshot' => $quotation->customer_name_snapshot,
                    'company_name_snapshot' => $quotation->company_name_snapshot,
                    'customer_email_snapshot' => $quotation->customer_email_snapshot,
                    'customer_phone_snapshot' => $quotation->customer_phone_snapshot,
                    'billing_address_snapshot' => $quotation->billing_address_snapshot,
                    'delivery_address' => $options['delivery_address'] ?? collect([$lead->city, $lead->state, $lead->postal_code])->filter()->implode(', ') ?: null,
                    // The agreed commercial terms are copied, never recalculated.
                    'subtotal' => $quotation->subtotal,
                    'discount_total' => $quotation->discount_total,
                    'tax_total' => $quotation->tax_total,
                    'freight_amount' => $quotation->freight_amount,
                    'grand_total' => $quotation->grand_total,
                    'payment_terms' => $quotation->payment_terms,
                    'delivery_terms' => $quotation->delivery_terms,
                    'installation_required' => (bool) ($options['installation_required'] ?? false),
                    'notes' => $options['notes'] ?? null,
                    'ordered_at' => now(),
                    'created_by' => $actor->id,
                ])->save();

                foreach ($quotation->items as $item) {
                    $order->items()->forceCreate([
                        'order_id' => $order->id,
                        'product_id' => $item->product_id,
                        'product_name_snapshot' => $item->product_name_snapshot,
                        'product_code_snapshot' => $item->product_code_snapshot,
                        'description_snapshot' => $item->description_snapshot,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'discount_amount' => $item->discount_amount,
                        'tax_rate' => $item->tax_rate,
                        'tax_amount' => $item->tax_amount,
                        'line_subtotal' => $item->line_subtotal,
                        'line_total' => $item->line_total,
                        'sort_order' => $item->sort_order,
                    ]);
                }

                $this->event($order, 'created', $actor, null, 'pending_confirmation', "Created from {$quotation->quotation_number}.");
                AuditLog::record('order.created', ['order_id' => $order->id, 'lead_id' => $lead->id, 'quotation_id' => $quotation->id], $order);

                $recipient = config('techmate.operations_notification_email') ?: config('techmate.sales_notification_email');
                Outbox::queue('order.created', $recipient, "order:{$order->id}:created", $lead->id, ['order_id' => $order->id]);

                return $order;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent conversion won the race; return its order rather than failing.
            return Order::query()->where('lead_id', $lead->id)->firstOrFail();
        }
    }

    public function advance(Order $order, string $status, User $actor, ?string $note = null): Order
    {
        Gate::forUser($actor)->authorize('manage', $order);

        return DB::transaction(function () use ($order, $status, $actor, $note) {
            $order = $this->lock($order);
            $from = $order->status;

            if (! $order->canTransitionTo($status)) {
                throw ValidationException::withMessages(['status' => "A {$from} order cannot move to {$status}."]);
            }

            if ($status === 'cancelled') {
                if (trim((string) $note) === '') {
                    throw ValidationException::withMessages(['note' => 'Give a reason for cancelling the order.']);
                }
                if (BigDecimal::of($order->amountReceived())->isPositive()) {
                    throw ValidationException::withMessages(['status' => 'Payments have been received. Void or reconcile them before cancelling.']);
                }
            }

            if ($status === 'completed' && $order->installation_required && ! $order->installation_completed_at) {
                throw ValidationException::withMessages(['status' => 'Installation and commissioning must be recorded before completing this order.']);
            }

            $order->forceFill(array_filter([
                'status' => $status,
                'confirmed_at' => $status === 'confirmed' ? now() : null,
                'dispatched_at' => $status === 'dispatched' ? now() : null,
                'delivered_at' => $status === 'delivered' ? now() : null,
                'completed_at' => $status === 'completed' ? now() : null,
                'cancelled_at' => $status === 'cancelled' ? now() : null,
                'cancellation_reason' => $status === 'cancelled' ? $note : null,
            ], fn ($v) => $v !== null))->save();

            $this->event($order, 'status_change', $actor, $from, $status, $note);
            AuditLog::record('order.status_changed', ['order_id' => $order->id, 'from' => $from, 'to' => $status], $order);

            return $order;
        });
    }

    public function recordInstallation(Order $order, User $actor, ?string $note = null): Order
    {
        Gate::forUser($actor)->authorize('manage', $order);

        return DB::transaction(function () use ($order, $actor, $note) {
            $order = $this->lock($order);

            if (! $order->installation_required) {
                throw ValidationException::withMessages(['status' => 'This order does not include installation.']);
            }
            if (! in_array($order->status, ['dispatched', 'delivered'], true)) {
                throw ValidationException::withMessages(['status' => 'Installation can be recorded once the equipment is dispatched or delivered.']);
            }
            if ($order->installation_completed_at) {
                throw ValidationException::withMessages(['status' => 'Installation is already recorded.']);
            }

            $order->forceFill(['installation_completed_at' => now()])->save();
            $this->event($order, 'installation_completed', $actor, $order->status, $order->status, $note);
            AuditLog::record('order.installation_completed', ['order_id' => $order->id], $order);

            return $order;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function recordPayment(Order $order, array $data, User $actor): Payment
    {
        Gate::forUser($actor)->authorize('recordPayment', $order);

        $input = Validator::make($data, [
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['amount.regex' => 'Enter an amount with at most two decimals.'])->validate();

        try {
            return DB::transaction(function () use ($order, $input, $actor) {
                $order = $this->lock($order);

                if ($order->status === 'cancelled') {
                    throw ValidationException::withMessages(['amount' => 'Payments cannot be recorded against a cancelled order.']);
                }

                $amount = BigDecimal::of($input['amount'])->toScale(2);
                if ($amount->isZero()) {
                    throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
                }

                $balance = BigDecimal::of($order->balanceDue());
                if ($amount->isGreaterThan($balance)) {
                    throw ValidationException::withMessages(['amount' => 'This exceeds the outstanding balance of '.$order->currency_code.' '.number_format((float) (string) $balance, 2).'.']);
                }

                $payment = new Payment;
                $payment->forceFill([
                    'order_id' => $order->id,
                    'amount' => (string) $amount,
                    'paid_at' => $input['paid_at'],
                    'method' => $input['method'],
                    'reference' => ($input['reference'] ?? null) ?: null,
                    'notes' => $input['notes'] ?? null,
                    'status' => 'recorded',
                    'recorded_by' => $actor->id,
                ])->save();

                $this->event($order, 'payment_recorded', $actor, $order->status, $order->status, "Payment {$order->currency_code} {$payment->amount} ({$payment->method}).");
                AuditLog::record('payment.recorded', ['order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => (string) $amount], $payment);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reference' => 'A payment with this reference has already been recorded for the order.']);
        }
    }

    public function verifyPayment(Payment $payment, User $actor): Payment
    {
        Gate::forUser($actor)->authorize('verifyPayment', $payment->order);

        return DB::transaction(function () use ($payment, $actor) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($payment->status !== 'recorded') {
                throw ValidationException::withMessages(['status' => 'Only a recorded payment can be verified.']);
            }
            // Separation of duties: the person who entered a payment cannot also be its verifier.
            if ($payment->recorded_by === $actor->id && ! $actor->isSuperAdmin()) {
                throw ValidationException::withMessages(['status' => 'A payment must be verified by someone other than the person who recorded it.']);
            }

            $payment->forceFill(['status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()])->save();
            $this->event($payment->order, 'payment_verified', $actor, null, null, "Payment #{$payment->id} verified.");
            AuditLog::record('payment.verified', ['order_id' => $payment->order_id, 'payment_id' => $payment->id], $payment);

            return $payment;
        });
    }

    /** A wrong entry is voided, not deleted, so the ledger keeps its history. */
    public function voidPayment(Payment $payment, string $reason, User $actor): Payment
    {
        Gate::forUser($actor)->authorize('verifyPayment', $payment->order);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for voiding the payment.']);
        }

        return DB::transaction(function () use ($payment, $reason, $actor) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($payment->status === 'voided') {
                throw ValidationException::withMessages(['status' => 'This payment is already voided.']);
            }

            $previous = $payment->status;
            $payment->forceFill(['status' => 'voided', 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason])->save();
            $this->event($payment->order, 'payment_voided', $actor, null, null, "Payment #{$payment->id} voided: {$reason}");
            AuditLog::record('payment.voided', ['order_id' => $payment->order_id, 'payment_id' => $payment->id, 'was' => $previous], $payment);

            return $payment;
        });
    }

    private function lock(Order $order): Order
    {
        return Order::query()->lockForUpdate()->findOrFail($order->getKey());
    }

    private function event(Order $order, string $event, ?User $actor, ?string $from, ?string $to, ?string $note = null): void
    {
        OrderEvent::query()->forceCreate([
            'order_id' => $order->id,
            'user_id' => $actor?->id,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'occurred_at' => now(),
        ]);
    }
}
