<?php

namespace Tests\Feature\Sales;

use App\Models\Lead;
use App\Models\Quotation;
use App\Models\User;
use App\Services\QuotationService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Tests\DatabaseTestCase;

/** Shared fixtures for the Phase 7 sales workflow tests. */
abstract class SalesTestCase extends DatabaseTestCase
{
    protected User $exec;

    protected User $otherExec;

    protected User $manager;

    protected User $admin;

    protected User $ops;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        Storage::fake('local');
        config(['techmate.sales_notification_email' => 'sales@example.test', 'techmate.operations_notification_email' => 'ops@example.test']);

        $this->exec = User::factory()->create(['role' => User::ROLE_SALES_EXECUTIVE, 'email' => 'exec@example.test']);
        $this->otherExec = User::factory()->create(['role' => User::ROLE_SALES_EXECUTIVE]);
        $this->manager = User::factory()->create(['role' => User::ROLE_SALES_MANAGER, 'email' => 'manager@example.test']);
        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->ops = User::factory()->create(['role' => User::ROLE_OPERATIONS]);
    }

    protected function lead(?User $owner = null, string $status = 'qualified', array $attributes = []): Lead
    {
        return Lead::factory()->status($status)->create([
            'assigned_to' => ($owner ?? $this->exec)->id,
            'email' => 'buyer@example.com',
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    protected function quotationData(array $overrides = []): array
    {
        return [
            'currency_code' => 'INR',
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(14)->toDateString(),
            'freight_amount' => '500.00',
            'payment_terms' => '50% advance, balance before dispatch',
            'items' => [
                ['product_name_snapshot' => 'Deck oven 3-deck', 'quantity' => 2, 'unit_price' => '100000.00', 'discount_amount' => '10000.00', 'tax_rate' => '18'],
                ['product_name_snapshot' => 'Dough mixer', 'quantity' => 1, 'unit_price' => '45000.50', 'discount_amount' => '0', 'tax_rate' => '12'],
            ],
            ...$overrides,
        ];
    }

    protected function service(): QuotationService
    {
        return app(QuotationService::class);
    }

    /** A quotation taken through draft -> approved by the right people. */
    protected function approvedQuotation(?Lead $lead = null, array $overrides = []): Quotation
    {
        $lead ??= $this->lead();
        $quotation = $this->service()->createDraft($lead, $this->quotationData($overrides), $this->exec);
        $this->service()->submitForApproval($quotation, $this->exec);

        return $this->service()->approve($quotation->fresh(), $this->manager);
    }

    protected function sentQuotation(?Lead $lead = null): Quotation
    {
        return $this->service()->send($this->approvedQuotation($lead)->fresh(), $this->exec);
    }

    protected function acceptedQuotation(?Lead $lead = null): Quotation
    {
        $sent = $this->sentQuotation($lead);

        return $this->service()->recordAcceptance($sent->fresh(), ['method' => 'purchase_order', 'reference' => 'PO-7781'], $this->exec);
    }
}
