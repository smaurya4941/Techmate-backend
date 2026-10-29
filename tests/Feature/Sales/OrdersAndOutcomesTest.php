<?php

namespace Tests\Feature\Sales;

use App\Models\Lead;
use App\Models\NotificationOutbox;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Services\LeadOutcomeService;
use App\Services\LeadWorkflow;
use App\Services\OrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class OrdersAndOutcomesTest extends SalesTestCase
{
    private function outcomes(): LeadOutcomeService
    {
        return app(LeadOutcomeService::class);
    }

    private function orders(): OrderService
    {
        return app(OrderService::class);
    }

    /** A won deal: returns the order. */
    private function wonOrder(array $options = []): Order
    {
        $lead = $this->lead();
        $quotation = $this->acceptedQuotation($lead);

        return $this->outcomes()->markWon($lead->fresh(), $quotation->fresh(), $this->manager, $options);
    }

    // ---- Won -------------------------------------------------------------

    public function test_winning_a_deal_creates_the_order_from_the_accepted_quotation(): void
    {
        $lead = $this->lead();
        $quotation = $this->acceptedQuotation($lead);

        $order = $this->outcomes()->markWon($lead->fresh(), $quotation->fresh(), $this->manager, ['installation_required' => true]);

        $lead->refresh();
        $this->assertSame('won', $lead->status);
        $this->assertNotNull($lead->closed_at);
        $this->assertSame($this->manager->id, $lead->closed_by);
        $this->assertSame($quotation->id, $lead->outcome_quotation_id);

        $this->assertMatchesRegularExpression('/^ORD-\d{4}-000001$/', $order->order_number);
        $this->assertSame('pending_confirmation', $order->status);
        $this->assertSame($quotation->id, $order->quotation_id);
        $this->assertSame($quotation->grand_total, $order->grand_total);
        $this->assertSame($quotation->payment_terms, $order->payment_terms);
        $this->assertCount(2, $order->items);
        $this->assertTrue($order->installation_required);

        $this->assertTrue($lead->activities()->where('activity_type', 'outcome')->exists());
        $mail = NotificationOutbox::where('type', 'order.created')->firstOrFail();
        $this->assertSame('ops@example.test', $mail->recipient_email);
    }

    public function test_conversion_is_idempotent(): void
    {
        $lead = $this->lead();
        $quotation = $this->acceptedQuotation($lead);

        $first = $this->outcomes()->markWon($lead->fresh(), $quotation->fresh(), $this->manager);
        $second = $this->outcomes()->markWon($lead->fresh(), $quotation->fresh(), $this->manager);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, NotificationOutbox::where('type', 'order.created')->count());
        $this->assertSame(1, $lead->activities()->where('activity_type', 'outcome')->count());
    }

    public function test_the_unique_constraints_are_the_final_guard_against_duplicate_orders(): void
    {
        $order = $this->wonOrder();
        $before = Order::count();

        // Calling the lower-level converter again (e.g. a second worker) returns the existing order.
        $again = $this->orders()->createFromQuotation($order->lead, $order->quotation, $this->manager);

        $this->assertSame($order->id, $again->id);
        $this->assertSame($before, Order::count());
    }

    public function test_won_requires_an_accepted_current_quotation_and_the_right_permission(): void
    {
        $lead = $this->lead();
        $sent = $this->sentQuotation($lead);

        try {
            $this->outcomes()->markWon($lead->fresh(), $sent->fresh(), $this->manager);
            $this->fail('A merely sent quotation is not a win.');
        } catch (ValidationException) {
        }
        $this->assertSame('quotation_sent', $lead->fresh()->status);

        $accepted = $this->service()->recordAcceptance($sent->fresh(), ['method' => 'purchase_order', 'reference' => 'PO-9'], $this->exec);

        // An executive may not close deals as won, even on their own lead.
        $this->expectException(AuthorizationException::class);
        $this->outcomes()->markWon($lead->fresh(), $accepted, $this->exec);
    }

    public function test_a_quotation_from_another_lead_cannot_win_this_one(): void
    {
        $lead = $this->lead();
        $this->sentQuotation($lead);
        $foreign = $this->acceptedQuotation($this->lead());

        $this->expectException(ValidationException::class);
        $this->outcomes()->markWon($lead->fresh(), $foreign->fresh(), $this->manager);
    }

    public function test_a_superseded_acceptance_cannot_win(): void
    {
        $lead = $this->lead();
        $accepted = $this->acceptedQuotation($lead);
        Quotation::whereKey($accepted->id)->update(['is_current' => false]);

        $this->expectException(ValidationException::class);
        $this->outcomes()->markWon($lead->fresh(), $accepted->fresh(), $this->manager);
    }

    public function test_an_order_keeps_the_agreed_terms_when_the_quotation_or_lead_change_later(): void
    {
        $order = $this->wonOrder();
        $total = $order->grand_total;

        $order->lead->update(['customer_name' => 'Renamed', 'company_name' => 'Elsewhere']);
        Quotation::whereKey($order->quotation_id)->update(['grand_total' => 1, 'subtotal' => 1, 'discount_total' => 0, 'tax_total' => 0, 'freight_amount' => 0]);

        $order->refresh();
        $this->assertSame($total, $order->grand_total);
        $this->assertNotSame('Renamed', $order->customer_name_snapshot);
    }

    // ---- Lost ------------------------------------------------------------

    public function test_losing_a_lead_requires_a_reason_and_keeps_the_record(): void
    {
        $lead = $this->lead();
        $this->service()->createDraft($lead, $this->quotationData(), $this->exec);
        app(LeadWorkflow::class)->scheduleFollowUp($lead->fresh(), ['title' => 'Call back', 'due_at' => now()->addDay()], $this->exec);

        foreach ([['nonsense', null], ['other', ''], ['other', null]] as [$reason, $notes]) {
            try {
                $this->outcomes()->markLost($lead->fresh(), $reason, $notes, null, $this->exec);
                $this->fail('Reason required.');
            } catch (ValidationException) {
            }
        }
        $this->assertNotSame('lost', $lead->fresh()->status);

        $this->outcomes()->markLost($lead->fresh(), 'price_budget', 'Went with a cheaper brand.', $lead->quotations()->first(), $this->exec);

        $lead->refresh();
        $this->assertSame('lost', $lead->status);
        $this->assertSame('price_budget', $lead->lost_reason);
        $this->assertNotNull($lead->closed_at);
        $this->assertSame($this->exec->id, $lead->closed_by);
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
        $this->assertSame(0, $lead->followUps()->active()->count(), 'Pending reminders on a dead lead are closed.');
        $this->assertTrue($lead->activities()->where('activity_type', 'outcome')->exists());
    }

    public function test_marking_lost_twice_changes_nothing_and_won_leads_cannot_be_lost(): void
    {
        $lead = $this->lead();
        $this->outcomes()->markLost($lead, 'competitor', null, null, $this->exec);
        $closedAt = $lead->fresh()->closed_at;

        $this->outcomes()->markLost($lead->fresh(), 'price_budget', null, null, $this->exec);
        $this->assertSame('competitor', $lead->fresh()->lost_reason);
        $this->assertEquals($closedAt, $lead->fresh()->closed_at);

        $won = $this->wonOrder()->lead;
        $this->expectException(ValidationException::class);
        $this->outcomes()->markLost($won->fresh(), 'competitor', null, null, $this->manager);
    }

    public function test_only_managers_reopen_lost_leads_and_won_deals_are_final(): void
    {
        $lead = $this->lead();
        $this->outcomes()->markLost($lead, 'project_postponed', null, null, $this->exec);

        try {
            $this->outcomes()->reopen($lead->fresh(), $this->exec, 'Customer is back');
            $this->fail('Executives cannot reopen.');
        } catch (AuthorizationException) {
        }

        $reopened = $this->outcomes()->reopen($lead->fresh(), $this->manager, 'Customer is back');
        $this->assertSame('contacted', $reopened->status);
        $this->assertNull($reopened->closed_at);
        $this->assertNull($reopened->lost_reason);
        $this->assertSame('project_postponed', $lead->activities()->where('activity_type', 'outcome')->first()->metadata['reason']);

        $won = $this->wonOrder()->lead;
        $this->expectException(ValidationException::class);
        $this->outcomes()->reopen($won->fresh(), $this->manager, 'No');
    }

    // ---- Fulfilment ------------------------------------------------------

    public function test_the_fulfilment_lifecycle_is_enforced_step_by_step(): void
    {
        $order = $this->wonOrder(['installation_required' => true]);

        try {
            $this->orders()->advance($order, 'dispatched', $this->ops);
            $this->fail('Steps cannot be skipped.');
        } catch (ValidationException) {
        }

        foreach (['confirmed', 'processing', 'ready_for_dispatch', 'dispatched', 'delivered'] as $status) {
            $order = $this->orders()->advance($order->fresh(), $status, $this->ops);
        }
        $this->assertNotNull($order->confirmed_at);
        $this->assertNotNull($order->dispatched_at);
        $this->assertNotNull($order->delivered_at);

        try {
            $this->orders()->advance($order->fresh(), 'completed', $this->ops);
            $this->fail('Installation must be recorded first.');
        } catch (ValidationException) {
        }

        $this->orders()->recordInstallation($order->fresh(), $this->ops, 'Commissioned on site');
        $done = $this->orders()->advance($order->fresh(), 'completed', $this->ops);

        $this->assertSame('completed', $done->status);
        $this->assertNotNull($done->completed_at);
        $this->assertSame('created', $done->events()->first()->event);
        $this->assertGreaterThanOrEqual(8, $done->events()->count());

        $this->expectException(ValidationException::class);
        $this->orders()->advance($done->fresh(), 'cancelled', $this->ops, 'too late');
    }

    public function test_fulfilment_is_independent_of_payment_and_vice_versa(): void
    {
        $order = $this->wonOrder();
        $order = $this->orders()->advance($order, 'confirmed', $this->ops);
        $order = $this->orders()->advance($order->fresh(), 'processing', $this->ops);

        // Still unpaid while processing.
        $this->assertSame('unpaid', $order->fresh()->paymentStatus());

        $this->orders()->recordPayment($order->fresh(), ['amount' => '1000', 'paid_at' => now()->toDateString(), 'method' => 'cash'], $this->ops);
        $this->assertSame('processing', $order->fresh()->status, 'Recording a payment never moves fulfilment.');
        $this->assertSame('partial', $order->fresh()->paymentStatus());
    }

    public function test_only_authorised_roles_manage_orders_and_executives_see_only_their_own(): void
    {
        $order = $this->wonOrder();

        foreach ([$this->exec, $this->otherExec] as $user) {
            try {
                $this->orders()->advance($order, 'confirmed', $user);
                $this->fail('Executives cannot progress orders.');
            } catch (AuthorizationException) {
            }
        }

        $this->assertTrue($this->exec->can('view', $order));
        $this->assertFalse($this->otherExec->can('view', $order));
        $this->assertTrue($this->ops->can('view', $order));
    }

    public function test_cancelling_needs_a_reason_and_is_blocked_while_payments_exist(): void
    {
        $order = $this->wonOrder();

        try {
            $this->orders()->advance($order, 'cancelled', $this->manager);
            $this->fail('Reason required.');
        } catch (ValidationException) {
        }

        $payment = $this->orders()->recordPayment($order->fresh(), ['amount' => '500', 'paid_at' => now()->toDateString(), 'method' => 'cash'], $this->manager);
        try {
            $this->orders()->advance($order->fresh(), 'cancelled', $this->manager, 'Customer withdrew');
            $this->fail('Payments on record block cancellation.');
        } catch (ValidationException) {
        }

        $this->orders()->voidPayment($payment->fresh(), 'Entered in error', $this->admin);
        $cancelled = $this->orders()->advance($order->fresh(), 'cancelled', $this->manager, 'Customer withdrew');
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('Customer withdrew', $cancelled->cancellation_reason);
    }

    // ---- Payments --------------------------------------------------------

    public function test_payments_are_a_ledger_and_the_balance_is_derived(): void
    {
        $order = $this->wonOrder();
        $total = (string) $order->grand_total; // 275100.56

        $this->orders()->recordPayment($order, ['amount' => '100000.00', 'paid_at' => now()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'UTR-1'], $this->ops);
        $this->orders()->recordPayment($order->fresh(), ['amount' => '75100.56', 'paid_at' => now()->toDateString(), 'method' => 'cheque', 'reference' => 'CHQ-9'], $this->ops);

        $order = $order->fresh();
        $this->assertSame('175100.56', $order->amountReceived());
        $this->assertSame('100000.00', $order->balanceDue());
        $this->assertSame('partial', $order->paymentStatus());
        $this->assertSame($total, $order->grand_total, 'The order total never changes with payments.');
    }

    public function test_payment_validation(): void
    {
        $order = $this->wonOrder();
        $base = ['amount' => '10', 'paid_at' => now()->toDateString(), 'method' => 'cash'];

        foreach ([
            ['amount' => '0'], ['amount' => '-5'], ['amount' => '10.555'], ['amount' => 'abc'],
            ['amount' => '999999999'], // exceeds the balance
            ['paid_at' => now()->addDay()->toDateString()], ['method' => 'barter'],
        ] as $bad) {
            try {
                $this->orders()->recordPayment($order->fresh(), [...$base, ...$bad], $this->ops);
                $this->fail('Expected a validation failure for '.json_encode($bad));
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, Payment::count());
    }

    public function test_a_repeated_receipt_reference_is_rejected(): void
    {
        $order = $this->wonOrder();
        $entry = ['amount' => '100', 'paid_at' => now()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'UTR-77'];

        $this->orders()->recordPayment($order, $entry, $this->ops);

        $this->expectException(ValidationException::class);
        $this->orders()->recordPayment($order->fresh(), $entry, $this->ops);
    }

    public function test_verification_needs_a_second_person_and_full_payment_needs_verification(): void
    {
        $order = $this->wonOrder();
        $full = $this->orders()->recordPayment($order, ['amount' => (string) $order->grand_total, 'paid_at' => now()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'UTR-F'], $this->manager);

        $this->assertSame('awaiting_verification', $order->fresh()->paymentStatus());

        try {
            $this->orders()->verifyPayment($full->fresh(), $this->manager);
            $this->fail('Recorder cannot verify their own entry.');
        } catch (ValidationException) {
        }

        try {
            $this->orders()->verifyPayment($full->fresh(), $this->ops);
            $this->fail('Operations record but do not verify.');
        } catch (AuthorizationException) {
        }

        $this->orders()->verifyPayment($full->fresh(), $this->admin);
        $this->assertSame('paid', $order->fresh()->paymentStatus());
        $this->assertSame('0.00', $order->fresh()->balanceDue());
        $this->assertSame($this->admin->id, $full->fresh()->verified_by);
    }

    public function test_voiding_removes_the_amount_from_the_balance_but_keeps_the_row(): void
    {
        $order = $this->wonOrder();
        $payment = $this->orders()->recordPayment($order, ['amount' => '2500', 'paid_at' => now()->toDateString(), 'method' => 'upi', 'reference' => 'U-1'], $this->ops);

        try {
            $this->orders()->voidPayment($payment->fresh(), '', $this->manager);
            $this->fail('Reason required.');
        } catch (ValidationException) {
        }

        $this->orders()->voidPayment($payment->fresh(), 'Bounced', $this->manager);

        $this->assertSame('0.00', $order->fresh()->amountReceived());
        $this->assertSame('unpaid', $order->fresh()->paymentStatus());
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'voided', 'void_reason' => 'Bounced']);

        $this->expectException(\LogicException::class);
        $payment->fresh()->delete();
    }

    public function test_payments_cannot_be_recorded_by_executives_or_against_cancelled_orders(): void
    {
        $order = $this->wonOrder();
        $entry = ['amount' => '10', 'paid_at' => now()->toDateString(), 'method' => 'cash'];

        try {
            $this->orders()->recordPayment($order, $entry, $this->exec);
            $this->fail('Executives cannot record payments.');
        } catch (AuthorizationException) {
        }

        $this->orders()->advance($order, 'cancelled', $this->manager, 'Withdrawn');
        $this->expectException(ValidationException::class);
        $this->orders()->recordPayment($order->fresh(), $entry, $this->ops);
    }

    public function test_lead_status_cannot_skip_to_won_even_through_the_system_path(): void
    {
        $lead = $this->lead(status: 'new');

        $this->expectException(ValidationException::class);
        app(LeadWorkflow::class)->systemMove($lead, 'won', $this->manager);
    }
}
