<?php

namespace Tests\Feature\Sales;

use App\Models\AuditEntry;
use App\Models\NotificationOutbox;
use App\Models\Product;
use App\Models\Quotation;
use App\Services\OutboxMessages;
use App\Services\QuotationPdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class QuotationLifecycleTest extends SalesTestCase
{
    public function test_totals_follow_the_approved_calculation_rules(): void
    {
        $quotation = $this->service()->createDraft($this->lead(), $this->quotationData(), $this->exec);

        // line 1: 2 x 100000 = 200000 - 10000 = 190000, tax 18% = 34200
        // line 2: 1 x 45000.50 = 45000.50, tax 12% = 5400.06 (half-up)
        $this->assertSame('245000.50', $quotation->subtotal);
        $this->assertSame('10000.00', $quotation->discount_total);
        $this->assertSame('39600.06', $quotation->tax_total);
        $this->assertSame('500.00', $quotation->freight_amount);
        $this->assertSame('275100.56', $quotation->grand_total);
        $this->assertSame('draft', $quotation->status);
        $this->assertSame('1', (string) $quotation->version);
        $this->assertCount(2, $quotation->items);
        $this->assertSame('224200.00', $quotation->items[0]->line_total);
    }

    public function test_client_supplied_totals_and_status_are_ignored(): void
    {
        $data = $this->quotationData();
        $data['grand_total'] = '1.00';
        $data['subtotal'] = '1.00';
        $data['status'] = 'sent';
        $data['items'][0]['line_total'] = '1.00';
        $data['items'][0]['tax_amount'] = '0.00';

        $quotation = $this->service()->createDraft($this->lead(), $data, $this->exec);

        $this->assertSame('275100.56', $quotation->grand_total);
        $this->assertSame('draft', $quotation->status);
        $this->assertSame('224200.00', $quotation->items[0]->line_total);
    }

    public function test_invalid_lines_are_rejected_and_nothing_is_saved(): void
    {
        $lead = $this->lead();

        foreach ([
            ['items' => []],
            ['items' => [['product_name_snapshot' => 'X', 'quantity' => 0, 'unit_price' => '10']]],
            ['items' => [['product_name_snapshot' => 'X', 'quantity' => 1, 'unit_price' => '10.123']]],
            ['items' => [['product_name_snapshot' => 'X', 'quantity' => 1, 'unit_price' => '10', 'discount_amount' => '11']]],
            ['items' => [['product_name_snapshot' => 'X', 'quantity' => 1, 'unit_price' => '10', 'tax_rate' => '101']]],
            ['items' => [['quantity' => 1, 'unit_price' => '10']]], // neither product nor name
            ['freight_amount' => '-5'],
            ['currency_code' => 'RUPEES'],
            ['valid_until' => now()->subDay()->toDateString()],
        ] as $bad) {
            try {
                $this->service()->createDraft($lead, $this->quotationData($bad), $this->exec);
                $this->fail('Expected validation failure for '.json_encode($bad));
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, Quotation::count());
    }

    public function test_catalogue_identity_is_snapshotted_from_the_server_record_not_the_form(): void
    {
        $product = Product::factory()->create(['name' => 'Real Name', 'sku' => 'REAL-1']);
        $data = $this->quotationData(['items' => [[
            'product_id' => $product->id, 'product_name_snapshot' => 'Spoofed', 'product_code_snapshot' => 'FAKE',
            'quantity' => 1, 'unit_price' => '100', 'discount_amount' => '0', 'tax_rate' => '0',
        ]]]);

        $quotation = $this->service()->createDraft($this->lead(), $data, $this->exec);
        $product->update(['name' => 'Renamed Later', 'price' => 1, 'currency_code' => 'INR']);

        $this->assertSame('Real Name', $quotation->items()->first()->product_name_snapshot);
        $this->assertSame('REAL-1', $quotation->items()->first()->product_code_snapshot);
    }

    public function test_only_a_draft_can_be_edited_and_edits_recalculate(): void
    {
        $quotation = $this->service()->createDraft($this->lead(), $this->quotationData(), $this->exec);

        $updated = $this->service()->updateDraft($quotation, $this->quotationData([
            'items' => [['product_name_snapshot' => 'Single item', 'quantity' => 1, 'unit_price' => '1000', 'discount_amount' => '0', 'tax_rate' => '10']],
            'freight_amount' => '0',
        ]), $this->exec);

        $this->assertSame('1100.00', $updated->grand_total);
        $this->assertCount(1, $updated->items);

        $this->service()->submitForApproval($quotation, $this->exec);
        $this->expectException(ValidationException::class);
        $this->service()->updateDraft($quotation->fresh(), $this->quotationData(), $this->exec);
    }

    public function test_approval_is_required_before_sending(): void
    {
        $quotation = $this->service()->createDraft($this->lead(), $this->quotationData(), $this->exec);

        foreach (['send', 'recordAcceptance'] as $method) {
            try {
                $method === 'send'
                    ? $this->service()->send($quotation, $this->exec)
                    : $this->service()->recordAcceptance($quotation, ['method' => 'purchase_order', 'reference' => 'PO'], $this->exec);
                $this->fail("{$method} must be refused for a draft");
            } catch (ValidationException) {
            }
        }

        $this->service()->submitForApproval($quotation, $this->exec);
        $this->expectException(ValidationException::class); // pending approval still cannot be sent
        $this->service()->send($quotation->fresh(), $this->exec);
    }

    public function test_executives_cannot_approve_and_authors_cannot_approve_their_own_work(): void
    {
        $quotation = $this->service()->createDraft($this->lead(), $this->quotationData(), $this->exec);
        $this->service()->submitForApproval($quotation, $this->exec);

        try {
            $this->service()->approve($quotation->fresh(), $this->exec);
            $this->fail('Executives hold no approval permission.');
        } catch (AuthorizationException) {
        }

        // A manager who authored the quotation cannot be its only approver; an admin can.
        $own = $this->service()->createDraft($this->lead($this->manager), $this->quotationData(), $this->manager);
        $this->service()->submitForApproval($own, $this->manager);
        try {
            $this->service()->approve($own->fresh(), $this->manager);
            $this->fail('Four-eyes rule.');
        } catch (ValidationException) {
        }

        $this->assertSame('approved', $this->service()->approve($own->fresh(), $this->admin)->status);
        $this->assertSame('approved', $this->service()->approve($quotation->fresh(), $this->manager)->status);
    }

    public function test_returning_for_changes_reopens_the_draft(): void
    {
        $quotation = $this->service()->createDraft($this->lead(), $this->quotationData(), $this->exec);
        $this->service()->submitForApproval($quotation, $this->exec);

        $this->service()->returnToDraft($quotation->fresh(), $this->manager, 'Discount too high.');

        $this->assertSame('draft', $quotation->fresh()->status);
        $this->assertNull($quotation->fresh()->submitted_at);
        $this->assertSame('Discount too high.', $quotation->events()->where('event', 'returned')->first()->note);
    }

    public function test_sending_generates_a_pdf_queues_the_email_and_moves_the_lead(): void
    {
        $lead = $this->lead();
        $quotation = $this->approvedQuotation($lead);

        $sent = $this->service()->send($quotation->fresh(), $this->exec);

        $this->assertSame('sent', $sent->status);
        $this->assertNotNull($sent->sent_at);
        $this->assertSame($this->exec->id, $sent->sent_by);
        $this->assertTrue(Storage::disk('local')->exists($sent->pdf_path));
        $binary = Storage::disk('local')->get($sent->pdf_path);
        $this->assertStringStartsWith('%PDF', $binary);
        $this->assertSame(hash('sha256', $binary), $sent->pdf_checksum);
        $this->assertSame('quotation_sent', $lead->fresh()->status);

        $mail = NotificationOutbox::where('type', 'quotation.sent')->firstOrFail();
        $this->assertSame('buyer@example.com', $mail->recipient_email);
        $this->assertSame('sent', $mail->status); // sync queue in tests
    }

    public function test_sending_twice_does_not_issue_or_email_twice(): void
    {
        $sent = $this->sentQuotation();

        try {
            $this->service()->send($sent->fresh(), $this->exec);
            $this->fail('Already sent.');
        } catch (ValidationException) {
        }

        $this->assertSame(1, NotificationOutbox::where('type', 'quotation.sent')->count());
        $this->assertSame(1, $sent->events()->where('event', 'sent')->count());
    }

    public function test_a_failed_email_does_not_revert_the_commercial_status(): void
    {
        $this->app->bind(OutboxMessages::class, fn () => new class extends OutboxMessages
        {
            public function build(NotificationOutbox $row): ?MailMessage
            {
                throw new \RuntimeException('SMTP unreachable');
            }
        });

        $sent = $this->sentQuotation();

        $this->assertSame('sent', $sent->fresh()->status);
        $mail = NotificationOutbox::where('type', 'quotation.sent')->firstOrFail();
        $this->assertSame('pending', $mail->status);
        $this->assertStringContainsString('SMTP unreachable', $mail->last_error);
    }

    public function test_revisions_preserve_previous_versions_and_only_one_is_current(): void
    {
        $lead = $this->lead();
        $v1 = $this->sentQuotation($lead);
        $v1Total = $v1->grand_total;
        $v1Pdf = $v1->pdf_path;

        $v2 = $this->service()->revise($v1->fresh(), $this->exec);
        $this->service()->updateDraft($v2, $this->quotationData([
            'items' => [['product_name_snapshot' => 'Deck oven 3-deck', 'quantity' => 3, 'unit_price' => '100000', 'discount_amount' => '0', 'tax_rate' => '18']],
            'freight_amount' => '0',
        ]), $this->exec);

        $v1 = $v1->fresh();
        $this->assertSame('superseded', $v1->status);
        $this->assertFalse($v1->is_current);
        $this->assertSame($v1Total, $v1->grand_total, 'The earlier offer is untouched.');
        $this->assertSame($v1Pdf, $v1->pdf_path);
        $this->assertCount(2, $v1->items);

        $this->assertSame(2, $v2->version);
        $this->assertSame($v1->series_number.'-V2', $v2->quotation_number);
        $this->assertTrue($v2->is_current);
        $this->assertSame($v1->id, $v2->supersedes_id);
        $this->assertSame('draft', $v2->status);
        $this->assertSame('354000.00', $v2->fresh()->grand_total);
        $this->assertSame('negotiation', $lead->fresh()->status);
        $this->assertSame(1, Quotation::where('lead_id', $lead->id)->where('is_current', true)->count());
    }

    public function test_an_accepted_quotation_is_final_and_cannot_be_revised_or_cancelled(): void
    {
        $accepted = $this->acceptedQuotation();

        foreach ([
            fn () => $this->service()->revise($accepted->fresh(), $this->exec),
            fn () => $this->service()->cancel($accepted->fresh(), 'oops', $this->exec),
            fn () => $this->service()->recordRejection($accepted->fresh(), 'no', $this->exec),
            fn () => $this->service()->updateDraft($accepted->fresh(), $this->quotationData(), $this->exec),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An accepted quotation must not change.');
            } catch (ValidationException) {
            }
        }

        $this->assertSame('accepted', $accepted->fresh()->status);
    }

    public function test_acceptance_needs_evidence_and_a_valid_current_offer(): void
    {
        $sent = $this->sentQuotation();

        foreach ([[], ['method' => 'purchase_order'], ['method' => 'smoke_signal', 'reference' => 'x'], ['reference' => 'x']] as $bad) {
            try {
                $this->service()->recordAcceptance($sent->fresh(), $bad, $this->exec);
                $this->fail('Evidence is mandatory.');
            } catch (ValidationException) {
            }
        }
        $this->assertSame('sent', $sent->fresh()->status);

        $accepted = $this->service()->recordAcceptance($sent->fresh(), ['method' => 'email_confirmation', 'note' => 'Written confirmation from the owner on 3 Nov.'], $this->exec);
        $this->assertSame('accepted', $accepted->status);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertSame($this->exec->id, $accepted->acceptance_recorded_by);
        $this->assertSame('email_confirmation', $accepted->acceptance_method);
    }

    public function test_an_expired_offer_cannot_be_accepted_and_the_command_expires_it(): void
    {
        $sent = $this->sentQuotation();
        Quotation::whereKey($sent->id)->update(['valid_until' => now()->subDays(2)->toDateString(), 'issue_date' => now()->subDays(20)->toDateString()]);

        try {
            $this->service()->recordAcceptance($sent->fresh(), ['method' => 'purchase_order', 'reference' => 'PO-1'], $this->exec);
            $this->fail('Expired offers cannot be accepted.');
        } catch (ValidationException) {
        }

        $this->artisan('techmate:expire-quotations')->assertSuccessful();
        $this->assertSame('expired', $sent->fresh()->status);
        $this->assertTrue($sent->events()->where('event', 'expired')->exists());

        // An expired offer can be revised into a fresh one.
        $this->assertSame(2, $this->service()->revise($sent->fresh(), $this->exec)->version);
    }

    public function test_only_one_live_quotation_per_lead(): void
    {
        $lead = $this->lead();
        $this->service()->createDraft($lead, $this->quotationData(), $this->exec);

        $this->expectException(ValidationException::class);
        $this->service()->createDraft($lead, $this->quotationData(), $this->exec);
    }

    public function test_quotations_cannot_be_prepared_for_closed_leads(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->createDraft($this->lead(status: 'lost'), $this->quotationData(), $this->exec);
    }

    public function test_executives_cannot_touch_other_executives_quotations(): void
    {
        $theirs = $this->service()->createDraft($this->lead($this->otherExec), $this->quotationData(), $this->otherExec);

        foreach ([
            fn () => $this->service()->createDraft($this->lead($this->otherExec), $this->quotationData(), $this->exec),
            fn () => $this->service()->updateDraft($theirs, $this->quotationData(), $this->exec),
            fn () => $this->service()->submitForApproval($theirs, $this->exec),
            fn () => $this->service()->cancel($theirs, 'x', $this->exec),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected an authorisation failure.');
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame('draft', $theirs->fresh()->status);
    }

    public function test_every_step_is_recorded_in_the_event_history_the_lead_timeline_and_the_audit_log(): void
    {
        $lead = $this->lead();
        $quotation = $this->sentQuotation($lead);

        $this->assertSame(['created', 'submitted', 'approved', 'sent'], $quotation->events()->pluck('event')->all());
        $this->assertGreaterThanOrEqual(4, $lead->activities()->where('activity_type', 'quotation')->count());
        $this->assertTrue(AuditEntry::where('event', 'quotation.sent')->where('subject_id', $quotation->id)->exists());

        $this->expectException(\LogicException::class);
        $quotation->events()->first()->update(['note' => 'rewritten']);
    }

    public function test_the_quotation_pdf_is_only_served_to_staff_who_can_see_it(): void
    {
        $quotation = $this->sentQuotation();

        $this->actingAs($this->exec)->get(route('admin.quotations.pdf', $quotation))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->otherExec)->get(route('admin.quotations.pdf', $quotation))->assertForbidden();
        $this->actingAs($this->ops)->get(route('admin.quotations.pdf', $quotation))->assertForbidden();
        auth()->logout();
        $this->get(route('admin.quotations.pdf', $quotation))->assertRedirect();
    }

    public function test_a_draft_preview_renders_from_saved_figures(): void
    {
        $draft = $this->service()->createDraft($this->lead(), $this->quotationData(), $this->exec);

        $this->actingAs($this->exec)->get(route('admin.quotations.pdf', $draft))->assertOk();
        $this->assertStringStartsWith('%PDF', app(QuotationPdf::class)->render($draft));
    }
}
