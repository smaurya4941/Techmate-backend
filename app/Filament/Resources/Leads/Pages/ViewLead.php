<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Support\Guarded;
use App\Filament\Support\Status;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use App\Services\LeadOutcomeService;
use App\Services\LeadWorkflow;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;

/** @property Lead $record */
class ViewLead extends ViewRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('logActivity')
                ->label('Log call / note')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->authorize('update')
                ->schema([
                    Select::make('activity_type')->label('Type')->required()->native(false)
                        ->options(['call' => 'Call', 'note' => 'Internal note', 'email' => 'Email', 'meeting' => 'Meeting / site visit'])->default('call'),
                    Textarea::make('description')->required()->rows(4)->maxLength(5000)
                        ->helperText('Record what actually happened. Internal notes are never shown to the customer.'),
                    DateTimePicker::make('occurred_at')->label('When')->default(now())->maxDate(now()->addMinute())->seconds(false)->required(),
                ])
                ->action(function (array $data) {
                    Guarded::run(fn () => app(LeadWorkflow::class)->logActivity(
                        $this->record, $data['activity_type'], $data['description'], auth()->user(), Carbon::parse($data['occurred_at'])
                    ), 'Activity logged');
                    $this->refreshRecord();
                }),
            Action::make('scheduleFollowUp')
                ->label('Schedule follow-up')
                ->icon('heroicon-o-calendar-days')
                ->authorize('update')
                ->visible(fn () => $this->record->isOpen())
                ->schema([
                    TextInput::make('title')->required()->maxLength(200),
                    Textarea::make('description')->rows(2)->maxLength(2000),
                    DateTimePicker::make('due_at')->label('Due')->required()->seconds(false)->minDate(now()->subMinute()),
                    Select::make('remind_before_minutes')->label('Reminder')->native(false)
                        ->options([null => 'No reminder', 15 => '15 minutes before', 30 => '30 minutes before', 60 => '1 hour before', 240 => '4 hours before', 1440 => '1 day before'])
                        ->default(config('techmate.sales.default_reminder_minutes'))
                        ->helperText('One reminder, plus one notice if the task becomes overdue.'),
                    Select::make('assigned_to')->label('Assign to')->searchable()
                        ->options(fn () => User::assignable()->orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => $this->record->assigned_to ?? auth()->id())
                        ->required(),
                ])
                ->action(function (array $data) {
                    Guarded::run(fn () => app(LeadWorkflow::class)->scheduleFollowUp($this->record, $data, auth()->user()), 'Follow-up scheduled');
                    $this->refreshRecord();
                }),
            Action::make('newQuotation')
                ->label('New quotation')
                ->icon('heroicon-o-document-plus')
                ->color('success')
                ->visible(fn () => $this->record->isOpen() && auth()->user()->can('create', [Quotation::class, $this->record]))
                ->url(fn () => QuotationResource::getUrl('create', ['lead' => $this->record->getKey()])),
            Action::make('changeStatus')
                ->label('Update status')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->authorize('update')
                ->visible(fn () => (Lead::MANUAL_TRANSITIONS[$this->record->status] ?? []) !== [])
                ->schema([
                    Select::make('status')->label('New status')->required()->native(false)
                        ->options(fn () => Status::options(Lead::MANUAL_TRANSITIONS[$this->record->status] ?? []))
                        ->helperText('Quotation stages follow the quotation; use Won / Lost to close a deal.'),
                    Textarea::make('note')->rows(2)->maxLength(2000)->helperText('Recorded in the lead timeline.'),
                ])
                ->action(function (array $data) {
                    Guarded::run(fn () => app(LeadWorkflow::class)->changeStatus($this->record, $data['status'], auth()->user(), $data['note'] ?? null), 'Status updated');
                    $this->refreshRecord();
                }),
            ActionGroup::make([
                $this->assignAction(),
                $this->priorityAction(),
                $this->markWonAction(),
                $this->markLostAction(),
                $this->reopenAction(),
                Action::make('viewOrder')
                    ->label('Open order')->icon('heroicon-o-truck')
                    ->visible(fn () => $this->record->status === 'won' && $this->record->order && auth()->user()->can('view', $this->record->order))
                    ->url(fn () => OrderResource::getUrl('view', ['record' => $this->record->order])),
            ])->label('More')->icon('heroicon-m-ellipsis-vertical')->button()->color('gray'),
        ];
    }

    private function assignAction(): Action
    {
        return Action::make('assign')
            ->label(fn () => $this->record->assigned_to ? 'Reassign' : 'Assign')
            ->icon('heroicon-o-user-plus')
            ->authorize('assign')
            ->visible(fn () => $this->record->isOpen())
            ->schema([
                Select::make('assigned_to')->label('Assign to')->required()->searchable()
                    ->options(fn () => User::assignable()->orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => $this->record->assigned_to),
                Textarea::make('note')->rows(2)->maxLength(500),
            ])
            ->action(function (array $data) {
                Guarded::run(fn () => app(LeadWorkflow::class)->assign($this->record, (int) $data['assigned_to'], auth()->user(), $data['note'] ?? null), 'Lead assignment updated');
                $this->refreshRecord();
            });
    }

    private function priorityAction(): Action
    {
        return Action::make('priority')
            ->label('Change priority')
            ->icon('heroicon-o-flag')
            ->authorize('update')
            ->visible(fn () => $this->record->isOpen())
            ->schema([
                Select::make('priority')->required()->native(false)->options(Status::options(Lead::PRIORITIES))
                    ->default(fn () => $this->record->priority),
            ])
            ->action(function (array $data) {
                Guarded::run(fn () => app(LeadWorkflow::class)->changePriority($this->record, $data['priority'], auth()->user()), 'Priority updated');
                $this->refreshRecord();
            });
    }

    private function markWonAction(): Action
    {
        return Action::make('markWon')
            ->label('Mark won')
            ->icon('heroicon-o-trophy')
            ->color('success')
            ->authorize('markWon')
            ->visible(fn () => $this->record->isOpen() && $this->acceptedQuotations()->isNotEmpty())
            ->modalDescription('Creates the order from the accepted quotation. This cannot be undone.')
            ->schema([
                Select::make('quotation_id')->label('Accepted quotation')->required()->native(false)
                    ->options(fn () => $this->acceptedQuotations()->mapWithKeys(fn (Quotation $q) => [$q->id => "{$q->quotation_number} · ".Status::money($q->currency_code, $q->grand_total)]))
                    ->default(fn () => $this->acceptedQuotations()->first()?->id),
                Textarea::make('delivery_address')->rows(2)->maxLength(1000)
                    ->default(fn () => collect([$this->record->city, $this->record->state, $this->record->postal_code])->filter()->implode(', ')),
                Toggle::make('installation_required')->label('Installation / commissioning included'),
                Textarea::make('notes')->rows(2)->maxLength(1000),
            ])
            ->action(function (array $data) {
                $quotation = Quotation::query()->where('lead_id', $this->record->id)->findOrFail($data['quotation_id']);
                $order = Guarded::run(fn () => app(LeadOutcomeService::class)->markWon($this->record, $quotation, auth()->user(), [
                    'delivery_address' => $data['delivery_address'] ?? null,
                    'installation_required' => (bool) ($data['installation_required'] ?? false),
                    'notes' => $data['notes'] ?? null,
                ]), 'Deal won - order created');
                $this->refreshRecord();

                if ($order instanceof Order && auth()->user()->can('view', $order)) {
                    $this->redirect(OrderResource::getUrl('view', ['record' => $order]));
                }
            });
    }

    private function markLostAction(): Action
    {
        return Action::make('markLost')
            ->label('Mark lost')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->authorize('markLost')
            ->visible(fn () => $this->record->isOpen())
            ->modalDescription('The lead is kept for reporting. Pending follow-ups are cancelled.')
            ->schema([
                Select::make('lost_reason')->label('Reason')->required()->native(false)->options(Lead::LOST_REASONS),
                Select::make('quotation_id')->label('Quotation involved (optional)')->native(false)
                    ->options(fn () => $this->record->quotations()->orderByDesc('id')->get()->mapWithKeys(fn (Quotation $q) => [$q->id => $q->quotation_number.' ('.Status::label($q->status).')'])),
                Textarea::make('outcome_notes')->label('Notes')->rows(3)->maxLength(2000)
                    ->required(fn ($get) => $get('lost_reason') === 'other')->live(),
            ])
            ->action(function (array $data) {
                $quotation = ! empty($data['quotation_id']) ? Quotation::query()->where('lead_id', $this->record->id)->find($data['quotation_id']) : null;
                Guarded::run(fn () => app(LeadOutcomeService::class)->markLost($this->record, $data['lost_reason'], $data['outcome_notes'] ?? null, $quotation, auth()->user()), 'Lead marked lost');
                $this->refreshRecord();
            });
    }

    private function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label('Reopen lead')
            ->icon('heroicon-o-arrow-uturn-left')
            ->authorize('reopen')
            ->visible(fn () => $this->record->status === 'lost')
            ->modalDescription('If this is a new project, consider creating a new enquiry for the same customer instead.')
            ->schema([Textarea::make('note')->label('Why is it being reopened?')->required()->rows(2)->maxLength(1000)])
            ->action(function (array $data) {
                Guarded::run(fn () => app(LeadOutcomeService::class)->reopen($this->record, auth()->user(), $data['note']), 'Lead reopened');
                $this->refreshRecord();
            });
    }

    /** @return Collection<int, Quotation> */
    private function acceptedQuotations()
    {
        return $this->record->quotations()->where('status', 'accepted')->where('is_current', true)->get();
    }

    private function refreshRecord(): void
    {
        $this->record->refresh();
    }
}
