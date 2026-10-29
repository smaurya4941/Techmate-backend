<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use App\Services\QuotationService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/** @property Quotation $record */
class EditQuotation extends EditRecord
{
    protected static string $resource = QuotationResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Only a draft is editable. Issued quotations are changed by creating a revision.
        abort_unless($this->record->isEditable() && auth()->user()->can('update', $this->record), 403);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['items'] = $this->record->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'product_name_snapshot' => $item->product_name_snapshot,
            'product_code_snapshot' => $item->product_code_snapshot,
            'description_snapshot' => $item->description_snapshot,
            'quantity' => $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'discount_amount' => (string) $item->discount_amount,
            'tax_rate' => (string) $item->tax_rate,
        ])->values()->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(QuotationService::class)->updateDraft($record, $data, auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return []; // no delete: quotations are cancelled, never removed
    }

    protected function getRedirectUrl(): ?string
    {
        return QuotationResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
