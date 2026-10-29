<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Lead;
use App\Models\Quotation;
use App\Services\QuotationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateQuotation extends CreateRecord
{
    protected static string $resource = QuotationResource::class;

    public ?Lead $lead = null;

    public function mount(): void
    {
        abort_unless(QuotationResource::canCreate(), 403);

        // Resolved through the scoped lead query: a lead the user cannot see is a 404, not a 403.
        $this->lead = LeadResource::getEloquentQuery()->with('products')->findOrFail((int) request()->query('lead'));

        abort_unless(auth()->user()->can('create', [Quotation::class, $this->lead]), 403);

        parent::mount();
    }

    public function getTitle(): string
    {
        return "New quotation for {$this->lead->public_reference}";
    }

    public function getSubheading(): ?string
    {
        return $this->lead->customer_name.($this->lead->company_name ? " · {$this->lead->company_name}" : '');
    }

    /** Pre-fills the lines from what the customer asked for; prices stay for the executive to enter. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['billing_address_snapshot'] = collect([$this->lead->city, $this->lead->state, $this->lead->postal_code])->filter()->implode(', ') ?: null;
        $data['items'] = $this->lead->products->map(fn ($p) => [
            'product_id' => $p->product_id,
            'product_name_snapshot' => $p->product_name_snapshot,
            'product_code_snapshot' => $p->product_code_snapshot,
            'description_snapshot' => null,
            'quantity' => $p->quantity,
            'unit_price' => null,
            'discount_amount' => 0,
            'tax_rate' => config('techmate.sales.default_tax_rate'),
        ])->values()->all() ?: [[
            'quantity' => 1, 'discount_amount' => 0, 'tax_rate' => config('techmate.sales.default_tax_rate'),
        ]];

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(QuotationService::class)->createDraft($this->lead, $data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return QuotationResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
