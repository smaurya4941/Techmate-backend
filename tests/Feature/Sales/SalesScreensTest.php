<?php

namespace Tests\Feature\Sales;

use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use App\Services\LeadOutcomeService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

class SalesScreensTest extends SalesTestCase
{
    private function wonOrder(): Order
    {
        $lead = $this->lead();
        $accepted = $this->acceptedQuotation($lead);

        return app(LeadOutcomeService::class)->markWon($lead->fresh(), $accepted->fresh(), $this->manager);
    }

    // ---- Quotation editor -----------------------------------------------------

    public function test_an_executive_prepares_a_quotation_from_their_lead_and_totals_come_from_the_server(): void
    {
        $lead = $this->lead($this->exec);

        Livewire::actingAs($this->exec)->withQueryParams(['lead' => $lead->id])->test(CreateQuotation::class)
            ->fillForm([
                'currency_code' => 'inr',
                'issue_date' => now()->toDateString(),
                'freight_amount' => '250',
                'items' => [
                    ['product_name_snapshot' => 'Planetary mixer', 'quantity' => 3, 'unit_price' => '1000', 'discount_amount' => '0', 'tax_rate' => '18'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $quotation = Quotation::firstOrFail();
        $this->assertSame('INR', $quotation->currency_code);
        $this->assertSame('540.00', $quotation->tax_total);
        $this->assertSame('3790.00', $quotation->grand_total); // 3000 + 540 tax + 250 freight
        $this->assertSame('draft', $quotation->status);
        $this->assertSame($this->exec->id, $quotation->created_by);
        $this->assertSame('quotation_in_progress', $lead->fresh()->status);
    }

    public function test_the_editor_prefills_the_customers_requested_products(): void
    {
        $lead = $this->lead($this->exec);
        $lead->products()->create(['product_name_snapshot' => 'Combi Oven', 'product_code_snapshot' => 'CO-10', 'quantity' => 4]);

        Livewire::actingAs($this->exec)->withQueryParams(['lead' => $lead->id])->test(CreateQuotation::class)
            ->assertFormSet(fn ($state) => array_diff(['Combi Oven'], collect($state['items'])->pluck('product_name_snapshot')->all()) === []);
    }

    public function test_a_lead_you_cannot_see_is_a_404_and_users_without_the_permission_a_403(): void
    {
        $theirs = $this->lead($this->otherExec);

        $this->actingAs($this->exec)->get('/admin/quotations/create?lead='.$theirs->id)->assertNotFound();
        $this->actingAs($this->exec)->get('/admin/quotations/create')->assertNotFound();
        $this->actingAs($this->ops)->get('/admin/quotations/create?lead='.$theirs->id)->assertForbidden();
        $this->actingAs($this->manager)->get('/admin/quotations/create?lead='.$theirs->id)->assertOk();
    }

    public function test_invalid_lines_show_errors_and_save_nothing(): void
    {
        $lead = $this->lead($this->exec);

        Livewire::actingAs($this->exec)->withQueryParams(['lead' => $lead->id])->test(CreateQuotation::class)
            ->fillForm(['items' => [['product_name_snapshot' => 'X', 'quantity' => 1, 'unit_price' => '', 'discount_amount' => '0', 'tax_rate' => '0']]])
            ->call('create')
            ->assertHasFormErrors(['items.0.unit_price']);

        $this->assertSame(0, Quotation::count());
    }

    public function test_only_drafts_open_in_the_editor(): void
    {
        $draft = $this->service()->createDraft($this->lead(), $this->quotationData(), $this->exec);
        $sent = $this->sentQuotation();

        $this->actingAs($this->exec)->get("/admin/quotations/{$draft->id}/edit")->assertOk();
        $this->actingAs($this->exec)->get("/admin/quotations/{$sent->id}/edit")->assertForbidden();
        $this->actingAs($this->otherExec)->get("/admin/quotations/{$draft->id}/edit")->assertNotFound();
        $this->actingAs($this->manager)->get("/admin/quotations/{$sent->id}/edit")->assertForbidden();
    }

    public function test_saving_the_editor_recalculates_the_totals(): void
    {
        $draft = $this->service()->createDraft($this->lead(), $this->quotationData(), $this->exec);

        Livewire::actingAs($this->exec)->test(EditQuotation::class, ['record' => $draft->id])
            ->fillForm(['freight_amount' => '0', 'items' => [['product_name_snapshot' => 'Only item', 'quantity' => 2, 'unit_price' => '50', 'discount_amount' => '0', 'tax_rate' => '10']]])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('110.00', $draft->fresh()->grand_total);
    }

    // ---- Quotation actions ----------------------------------------------------

    public function test_the_approval_buttons_follow_status_and_permission(): void
    {
        $draft = $this->service()->createDraft($this->lead(), $this->quotationData(), $this->exec);

        Livewire::actingAs($this->exec)->test(ViewQuotation::class, ['record' => $draft->id])
            ->assertActionVisible('submit')->assertActionHidden('approve')->assertActionHidden('send')->assertActionVisible('edit');

        Livewire::actingAs($this->exec)->test(ViewQuotation::class, ['record' => $draft->id])->callAction('submit');
        $this->assertSame('pending_approval', $draft->fresh()->status);

        Livewire::actingAs($this->exec)->test(ViewQuotation::class, ['record' => $draft->id])
            ->assertActionHidden('approve')->assertActionHidden('returnToDraft')->assertActionHidden('edit');

        Livewire::actingAs($this->manager)->test(ViewQuotation::class, ['record' => $draft->id])
            ->assertActionVisible('approve')->callAction('approve', ['note' => 'Margins fine']);
        $this->assertSame('approved', $draft->fresh()->status);
        $this->assertSame('Margins fine', $draft->fresh()->approval_note);

        Livewire::actingAs($this->exec)->test(ViewQuotation::class, ['record' => $draft->id])
            ->assertActionVisible('send')->callAction('send');
        $this->assertSame('sent', $draft->fresh()->status);
    }

    public function test_acceptance_and_revision_from_the_view_page(): void
    {
        $sent = $this->sentQuotation();

        Livewire::actingAs($this->exec)->test(ViewQuotation::class, ['record' => $sent->id])
            ->callAction('recordAcceptance', ['method' => '', 'reference' => '', 'note' => ''])
            ->assertHasActionErrors();
        $this->assertSame('sent', $sent->fresh()->status);

        Livewire::actingAs($this->exec)->test(ViewQuotation::class, ['record' => $sent->id])
            ->callAction('recordAcceptance', ['method' => 'signed_acceptance', 'reference' => 'SIGNED-12', 'note' => null])
            ->assertHasNoActionErrors();
        $this->assertSame('accepted', $sent->fresh()->status);

        Livewire::actingAs($this->exec)->test(ViewQuotation::class, ['record' => $sent->id])
            ->assertActionHidden('send')->assertActionHidden('recordAcceptance');
    }

    public function test_the_quotation_list_is_scoped_and_has_work_queues(): void
    {
        $mine = $this->service()->createDraft($this->lead($this->exec), $this->quotationData(), $this->exec);
        $theirs = $this->service()->createDraft($this->lead($this->otherExec), $this->quotationData(), $this->otherExec);
        $this->service()->submitForApproval($theirs, $this->otherExec);

        Livewire::actingAs($this->exec)->test(ListQuotations::class)
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);

        Livewire::actingAs($this->manager)->test(ListQuotations::class)->set('activeTab', 'approval')
            ->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$mine]);

        $this->actingAs($this->exec)->get("/admin/quotations/{$theirs->id}")->assertNotFound();
    }

    // ---- Lead outcome screens ---------------------------------------------------

    public function test_mark_won_is_a_manager_action_available_only_with_an_accepted_quotation(): void
    {
        $lead = $this->lead($this->exec);
        $this->sentQuotation($lead);

        Livewire::actingAs($this->manager)->test(ViewLead::class, ['record' => $lead->id])->assertActionHidden('markWon');

        $quotation = $lead->quotations()->first();
        $this->service()->recordAcceptance($quotation->fresh(), ['method' => 'purchase_order', 'reference' => 'PO-5'], $this->exec);

        Livewire::actingAs($this->exec)->test(ViewLead::class, ['record' => $lead->id])->assertActionHidden('markWon');
        Livewire::actingAs($this->manager)->test(ViewLead::class, ['record' => $lead->id])
            ->assertActionVisible('markWon')
            ->callAction('markWon', ['quotation_id' => $quotation->id, 'installation_required' => true, 'delivery_address' => 'Plot 4, Pune']);

        $this->assertSame('won', $lead->fresh()->status);
        $order = Order::firstOrFail();
        $this->assertSame('Plot 4, Pune', $order->delivery_address);
        $this->assertTrue($order->installation_required);
    }

    public function test_mark_lost_requires_a_reason_on_the_form(): void
    {
        $lead = $this->lead($this->exec);

        Livewire::actingAs($this->exec)->test(ViewLead::class, ['record' => $lead->id])
            ->callAction('markLost', ['lost_reason' => null])->assertHasActionErrors(['lost_reason']);
        $this->assertSame('qualified', $lead->fresh()->status);

        Livewire::actingAs($this->exec)->test(ViewLead::class, ['record' => $lead->id])
            ->callAction('markLost', ['lost_reason' => 'competitor', 'outcome_notes' => 'Chose a rival'])->assertHasNoActionErrors();
        $this->assertSame('lost', $lead->fresh()->status);

        Livewire::actingAs($this->exec)->test(ViewLead::class, ['record' => $lead->id])
            ->assertActionHidden('markLost')->assertActionHidden('reopen');
        Livewire::actingAs($this->manager)->test(ViewLead::class, ['record' => $lead->id])->assertActionVisible('reopen');
    }

    public function test_a_closed_lead_page_renders_for_every_sales_role(): void
    {
        $order = $this->wonOrder();

        foreach ([$this->manager, $this->admin, $this->exec] as $user) {
            $this->actingAs($user)->get('/admin/leads/'.$order->lead_id)->assertOk()->assertSee($order->order_number)->assertSee('Original enquiry');
        }
        $this->actingAs($this->otherExec)->get('/admin/leads/'.$order->lead_id)->assertNotFound();
    }

    // ---- Orders -----------------------------------------------------------------

    public function test_order_visibility_by_role(): void
    {
        $order = $this->wonOrder();

        foreach ([$this->ops, $this->manager, $this->admin, $this->exec] as $user) {
            Livewire::actingAs($user)->test(ListOrders::class)->assertCanSeeTableRecords([$order]);
            $this->actingAs($user)->get("/admin/orders/{$order->id}")->assertOk()->assertSee($order->order_number);
        }
        Livewire::actingAs($this->otherExec)->test(ListOrders::class)->assertCanNotSeeTableRecords([$order]);
        $this->actingAs($this->otherExec)->get("/admin/orders/{$order->id}")->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CATALOGUE_MANAGER]))->get('/admin/orders')->assertForbidden();
    }

    public function test_operations_progress_an_order_from_the_view_page(): void
    {
        $order = $this->wonOrder();

        Livewire::actingAs($this->exec)->test(ViewOrder::class, ['record' => $order->id])
            ->assertActionHidden('confirm')->assertActionHidden('advance')->assertActionHidden('cancel');

        $page = Livewire::actingAs($this->ops)->test(ViewOrder::class, ['record' => $order->id]);
        $page->assertActionVisible('confirm')->assertActionHidden('advance')->callAction('confirm');
        $this->assertSame('confirmed', $order->fresh()->status);

        Livewire::actingAs($this->ops)->test(ViewOrder::class, ['record' => $order->id])
            ->assertActionVisible('advance')->callAction('advance', ['note' => 'Picked']);
        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_payments_screen_records_verifies_and_voids_with_separation_of_duties(): void
    {
        $order = $this->wonOrder();
        $rm = fn (User $user) => Livewire::actingAs($user)->test(PaymentsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class]);

        $rm($this->exec)->assertActionHidden(TestAction::make('record')->table());

        $rm($this->ops)->callAction(TestAction::make('record')->table(), [
            'amount' => '5000', 'paid_at' => now()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'UTR-55',
        ])->assertHasNoActionErrors();

        $payment = $order->payments()->firstOrFail();
        $this->assertSame('recorded', $payment->status);

        $rm($this->ops)->assertActionHidden(TestAction::make('verify')->table($payment));
        $rm($this->manager)->callAction(TestAction::make('verify')->table($payment));
        $this->assertSame('verified', $payment->fresh()->status);

        $rm($this->manager)->callAction(TestAction::make('void')->table($payment), ['reason' => 'Bounced cheque']);
        $this->assertSame('voided', $payment->fresh()->status);
        $this->assertSame('0.00', $order->fresh()->amountReceived());
    }

    public function test_every_new_screen_renders_for_the_roles_that_own_it(): void
    {
        $this->wonOrder();

        $this->actingAs($this->admin)->get('/admin')->assertOk();
        foreach ([$this->manager, $this->ops, $this->exec, $this->admin] as $user) {
            $this->actingAs($user)->get('/admin')->assertOk();
        }
        foreach (['/admin/follow-ups', '/admin/orders', '/admin/quotations', '/admin/leads', '/admin/sales-reports'] as $url) {
            $this->actingAs($this->manager)->get($url)->assertOk();
        }
    }
}
