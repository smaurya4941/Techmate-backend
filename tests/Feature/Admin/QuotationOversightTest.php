<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\QuotationWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class QuotationOversightTest extends AdminTestCase
{
    private User $exec;

    private User $otherExec;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exec = $this->staff(User::ROLE_SALES_EXECUTIVE);
        $this->otherExec = $this->staff(User::ROLE_SALES_EXECUTIVE);
        $this->manager = $this->staff(User::ROLE_SALES_MANAGER);
    }

    private function quotationFor(User $owner, string $status = 'draft'): Quotation
    {
        $quotation = Quotation::factory()->create([
            'lead_id' => Lead::factory()->create(['assigned_to' => $owner->id])->id,
            'status' => $status,
        ]);

        return $quotation;
    }

    public function test_visibility_is_scoped_by_role(): void
    {
        $mine = $this->quotationFor($this->exec);
        $theirs = $this->quotationFor($this->otherExec);

        Livewire::actingAs($this->exec)->test(ListQuotations::class)
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);

        foreach ([$this->manager, $this->superAdmin()] as $user) {
            Livewire::actingAs($user)->test(ListQuotations::class)->assertCanSeeTableRecords([$mine, $theirs]);
        }

        $this->actingAs($this->exec)->get("/admin/quotations/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->exec)->get("/admin/quotations/{$mine->id}")->assertOk();
        $this->actingAs($this->catalogueManager())->get('/admin/quotations')->assertForbidden();
        $this->actingAs($this->catalogueManager())->get("/admin/quotations/{$mine->id}")->assertNotFound();
    }

    public function test_view_shows_customer_snapshot_totals_and_line_items(): void
    {
        $quotation = $this->quotationFor($this->manager);
        $quotation->forceFill(['grand_total' => 1180, 'subtotal' => 1000, 'tax_total' => 180])->save();
        QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'product_name_snapshot' => 'Snapshot Oven']);

        $this->actingAs($this->manager)->get("/admin/quotations/{$quotation->id}")
            ->assertOk()->assertSee($quotation->quotation_number)->assertSee('Snapshot Oven')->assertSee('1,180.00');
    }

    public function test_status_moves_only_along_permitted_transitions(): void
    {
        $quotation = $this->quotationFor($this->exec);
        $workflow = app(QuotationWorkflow::class);

        $workflow->changeStatus($quotation, 'sent', $this->manager);
        $this->assertNotNull($quotation->fresh()->sent_at);
        $workflow->changeStatus($quotation, 'accepted', $this->manager);
        $this->assertNotNull($quotation->fresh()->accepted_at);
        $this->assertSame(2, $quotation->lead->activities()->count());

        // Accepted quotations are final: the policy refuses any further status change.
        $this->expectException(AuthorizationException::class);
        $workflow->changeStatus($quotation->fresh(), 'cancelled', $this->manager);
    }

    public function test_skipping_steps_is_refused(): void
    {
        $quotation = $this->quotationFor($this->exec);

        $this->expectException(ValidationException::class);
        app(QuotationWorkflow::class)->changeStatus($quotation, 'accepted', $this->manager);
    }

    public function test_sales_executives_cannot_change_quotation_status(): void
    {
        $quotation = $this->quotationFor($this->exec);

        try {
            app(QuotationWorkflow::class)->changeStatus($quotation, 'sent', $this->exec);
            $this->fail('Expected an authorisation failure.');
        } catch (AuthorizationException) {
        }

        $this->assertSame('draft', $quotation->fresh()->status);

        Livewire::actingAs($this->exec)->test(ViewQuotation::class, ['record' => $quotation->getKey()])
            ->assertActionHidden('changeStatus');
    }

    public function test_managers_change_status_from_the_view_page(): void
    {
        $quotation = $this->quotationFor($this->exec);

        Livewire::actingAs($this->manager)->test(ViewQuotation::class, ['record' => $quotation->getKey()])
            ->callAction('changeStatus', ['status' => 'sent']);

        $this->assertSame('sent', $quotation->fresh()->status);
    }

    public function test_quotations_cannot_be_created_edited_or_deleted_from_the_panel(): void
    {
        $quotation = $this->quotationFor($this->manager);

        foreach ([$this->superAdmin(), $this->manager, $this->exec] as $user) {
            $this->assertFalse($user->can('create', Quotation::class));
            $this->assertFalse($user->can('update', $quotation));
            $this->assertFalse($user->can('delete', $quotation));
        }

        $this->actingAs($this->superAdmin())->get("/admin/quotations/{$quotation->id}/edit")->assertNotFound();
        $this->actingAs($this->superAdmin())->get('/admin/quotations/create')->assertNotFound();
    }

    public function test_catalogue_changes_never_rewrite_quotation_snapshots(): void
    {
        $product = Product::factory()->create(['name' => 'Original Name']);
        $quotation = $this->quotationFor($this->manager);
        $item = QuotationItem::factory()->create([
            'quotation_id' => $quotation->id, 'product_id' => $product->id, 'product_name_snapshot' => 'Original Name',
        ]);

        $product->update(['name' => 'Renamed']);
        $product->delete();

        $this->assertSame('Original Name', $item->fresh()->product_name_snapshot);
    }
}
