<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Support\Guarded;
use App\Models\Quotation;
use App\Services\QuotationWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;

/** @property Quotation $record */
class ViewQuotation extends ViewRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('changeStatus')
                ->label('Update status')
                ->icon('heroicon-o-arrow-path')
                ->authorize('updateStatus')
                ->schema([
                    Select::make('status')->label('New status')->required()->native(false)
                        ->options(fn () => collect($this->record->nextStatuses())->mapWithKeys(fn ($s) => [$s => ucfirst($s)])),
                ])
                ->requiresConfirmation()
                ->modalDescription('Status changes are recorded on the lead timeline. Quotation contents are not changed.')
                ->action(function (array $data) {
                    Guarded::run(fn () => app(QuotationWorkflow::class)->changeStatus($this->record, $data['status'], auth()->user()), 'Quotation status updated');
                    $this->record->refresh();
                }),
        ];
    }
}
