<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Guarded;
use App\Filament\Support\Status;
use App\Models\Order;
use App\Services\OrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

/** @property Order $record */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        $service = fn () => app(OrderService::class);

        return [
            Action::make('confirm')->label('Confirm order')->icon('heroicon-o-check-badge')->color('success')
                ->authorize('manage')->visible(fn () => $this->record->status === 'pending_confirmation')
                ->requiresConfirmation()
                ->modalDescription('Confirm that the acceptance conditions are met (for example the purchase order or advance has been received).')
                ->action(function () use ($service) {
                    Guarded::run(fn () => $service()->advance($this->record, 'confirmed', auth()->user()), 'Order confirmed');
                    $this->record->refresh();
                }),
            Action::make('advance')->label(fn () => 'Move to '.Status::label($this->nextStep()))->icon('heroicon-o-arrow-right-circle')->color('primary')
                ->authorize('manage')->visible(fn () => $this->nextStep() !== null)
                ->schema([Textarea::make('note')->rows(2)->maxLength(1000)->helperText('Recorded in the fulfilment timeline.')])
                ->action(function (array $data) use ($service) {
                    Guarded::run(fn () => $service()->advance($this->record, $this->nextStep(), auth()->user(), $data['note'] ?? null), 'Order updated');
                    $this->record->refresh();
                }),
            Action::make('installation')->label('Installation completed')->icon('heroicon-o-wrench-screwdriver')
                ->authorize('manage')
                ->visible(fn () => $this->record->installation_required && ! $this->record->installation_completed_at && in_array($this->record->status, ['dispatched', 'delivered'], true))
                ->schema([Textarea::make('note')->label('Commissioning notes')->rows(2)->maxLength(1000)])
                ->action(function (array $data) use ($service) {
                    Guarded::run(fn () => $service()->recordInstallation($this->record, auth()->user(), $data['note'] ?? null), 'Installation recorded');
                    $this->record->refresh();
                }),
            Action::make('cancel')->label('Cancel order')->icon('heroicon-o-x-circle')->color('danger')
                ->authorize('manage')->visible(fn () => $this->record->canTransitionTo('cancelled'))
                ->schema([Textarea::make('note')->label('Reason')->required()->rows(2)->maxLength(1000)])
                ->modalDescription('Cancelling is blocked while payments are on record. Void them first.')
                ->action(function (array $data) use ($service) {
                    Guarded::run(fn () => $service()->advance($this->record, 'cancelled', auth()->user(), $data['note']), 'Order cancelled');
                    $this->record->refresh();
                }),
        ];
    }

    /** The one forward step from the current fulfilment status (confirmation and cancellation have their own buttons). */
    private function nextStep(): ?string
    {
        return match ($this->record->status) {
            'confirmed' => 'processing',
            'processing' => 'ready_for_dispatch',
            'ready_for_dispatch' => 'dispatched',
            'dispatched' => 'delivered',
            'delivered' => 'completed',
            default => null,
        };
    }
}
