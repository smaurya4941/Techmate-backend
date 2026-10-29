<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Support\Guarded;
use App\Models\Quotation;
use App\Services\QuotationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

/** @property Quotation $record */
class ViewQuotation extends ViewRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        $is = fn (string ...$statuses) => fn () => in_array($this->record->status, $statuses, true);
        $service = fn () => app(QuotationService::class);

        return [
            Action::make('edit')->label('Edit draft')->icon('heroicon-o-pencil-square')
                ->authorize('update')->visible($is('draft'))
                ->url(fn () => QuotationResource::getUrl('edit', ['record' => $this->record])),
            Action::make('submit')->label('Submit for approval')->icon('heroicon-o-paper-airplane')->color('primary')
                ->authorize('manage')->visible($is('draft'))->requiresConfirmation()
                ->modalDescription('The draft can no longer be edited while it waits for approval.')
                ->action(function () use ($service) {
                    Guarded::run(fn () => $service()->submitForApproval($this->record, auth()->user()), 'Submitted for approval');
                    $this->record->refresh();
                }),
            Action::make('approve')->label('Approve')->icon('heroicon-o-check-badge')->color('success')
                ->authorize('approve')->visible($is('pending_approval'))
                ->schema([Textarea::make('note')->label('Approval note (optional)')->rows(2)->maxLength(1000)])
                ->action(function (array $data) use ($service) {
                    Guarded::run(fn () => $service()->approve($this->record, auth()->user(), $data['note'] ?? null), 'Quotation approved');
                    $this->record->refresh();
                }),
            Action::make('returnToDraft')->label('Return for changes')->icon('heroicon-o-arrow-uturn-left')->color('warning')
                ->authorize('approve')->visible($is('pending_approval'))
                ->schema([Textarea::make('note')->label('What needs to change?')->required()->rows(3)->maxLength(1000)])
                ->action(function (array $data) use ($service) {
                    Guarded::run(fn () => $service()->returnToDraft($this->record, auth()->user(), $data['note']), 'Returned to the author');
                    $this->record->refresh();
                }),
            Action::make('send')->label('Send to customer')->icon('heroicon-o-envelope')->color('success')
                ->authorize('manage')->visible($is('approved'))->requiresConfirmation()
                ->modalHeading('Send quotation to the customer?')
                ->modalDescription(fn () => 'The PDF is generated and e-mailed to '.($this->record->customer_email_snapshot ?: 'the customer').'. The quotation then becomes read-only.')
                ->action(function () use ($service) {
                    Guarded::run(fn () => $service()->send($this->record, auth()->user()), 'Quotation issued - e-mail queued');
                    $this->record->refresh();
                }),
            Action::make('recordAcceptance')->label('Record acceptance')->icon('heroicon-o-hand-thumb-up')->color('success')
                ->authorize('manage')->visible($is('sent'))
                ->modalDescription('Record the customer\'s formal acceptance and its evidence. Opening an e-mail is not acceptance.')
                ->schema([
                    Select::make('method')->label('How was it accepted?')->required()->native(false)->options(Quotation::ACCEPTANCE_METHODS),
                    TextInput::make('reference')->label('Document / PO / receipt reference')->maxLength(100),
                    Textarea::make('note')->label('Evidence notes')->rows(3)->maxLength(2000)
                        ->helperText('Required if there is no reference.'),
                ])
                ->action(function (array $data) use ($service) {
                    Guarded::run(fn () => $service()->recordAcceptance($this->record, $data, auth()->user()), 'Acceptance recorded');
                    $this->record->refresh();
                }),
            ActionGroup::make([
                Action::make('revise')->label('Create revision')->icon('heroicon-o-document-duplicate')
                    ->authorize('manage')->visible(fn () => $this->record->canBeRevised())->requiresConfirmation()
                    ->modalDescription('A new draft version is created from this one. An approved or sent version is marked superseded and kept for history.')
                    ->action(function () use ($service) {
                        $revision = Guarded::run(fn () => $service()->revise($this->record, auth()->user()), 'Revision created');
                        if ($revision instanceof Quotation) {
                            $this->redirect(QuotationResource::getUrl('view', ['record' => $revision]));
                        }
                    }),
                Action::make('resend')->label('E-mail again')->icon('heroicon-o-arrow-path')
                    ->authorize('manage')->visible($is('sent'))->requiresConfirmation()
                    ->action(function () use ($service) {
                        Guarded::run(fn () => $service()->resend($this->record, auth()->user()), 'E-mail queued again');
                        $this->record->refresh();
                    }),
                Action::make('recordRejection')->label('Record rejection')->icon('heroicon-o-hand-thumb-down')->color('danger')
                    ->authorize('manage')->visible($is('sent'))
                    ->schema([Textarea::make('reason')->label('Why did the customer decline?')->required()->rows(3)->maxLength(2000)])
                    ->action(function (array $data) use ($service) {
                        Guarded::run(fn () => $service()->recordRejection($this->record, $data['reason'], auth()->user()), 'Rejection recorded');
                        $this->record->refresh();
                    }),
                Action::make('cancel')->label('Cancel quotation')->icon('heroicon-o-x-circle')->color('danger')
                    ->authorize('manage')->visible($is('draft', 'pending_approval', 'approved', 'sent'))
                    ->schema([Textarea::make('reason')->required()->rows(2)->maxLength(1000)])
                    ->action(function (array $data) use ($service) {
                        Guarded::run(fn () => $service()->cancel($this->record, $data['reason'], auth()->user()), 'Quotation cancelled');
                        $this->record->refresh();
                    }),
            ])->label('More')->icon('heroicon-m-ellipsis-vertical')->button()->color('gray'),
            Action::make('pdf')->label(fn () => $this->record->pdf_path ? 'Open issued PDF' : 'Preview PDF')->icon('heroicon-o-document-arrow-down')->color('gray')
                ->url(fn () => route('admin.quotations.pdf', $this->record), shouldOpenInNewTab: true),
        ];
    }
}
