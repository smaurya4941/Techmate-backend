<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Support\Guarded;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadWorkflow;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

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
                        ->options(['call' => 'Call', 'note' => 'Note', 'email' => 'Email', 'meeting' => 'Meeting'])->default('call'),
                    Textarea::make('description')->required()->rows(4)->maxLength(5000),
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
                ->schema([
                    TextInput::make('title')->required()->maxLength(200),
                    Textarea::make('description')->rows(2)->maxLength(2000),
                    DateTimePicker::make('due_at')->label('Due')->required()->seconds(false)->minDate(now()->subMinute()),
                    Select::make('assigned_to')->label('Assign to')->searchable()
                        ->options(fn () => User::assignable()->orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => $this->record->assigned_to ?? auth()->id())
                        ->required(),
                ])
                ->action(function (array $data) {
                    Guarded::run(fn () => app(LeadWorkflow::class)->scheduleFollowUp($this->record, $data, auth()->user()), 'Follow-up scheduled');
                    $this->refreshRecord();
                }),
            Action::make('changeStatus')
                ->label('Update status')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->authorize('update')
                ->visible(fn () => (Lead::TRANSITIONS[$this->record->status] ?? []) !== [])
                ->schema([
                    Select::make('status')->label('New status')->required()->native(false)
                        ->options(fn () => collect(Lead::TRANSITIONS[$this->record->status] ?? [])->mapWithKeys(fn ($s) => [$s => ucfirst($s)])),
                    Textarea::make('note')->rows(2)->maxLength(2000)->helperText('Recorded in the lead timeline.'),
                ])
                ->action(function (array $data) {
                    Guarded::run(fn () => app(LeadWorkflow::class)->changeStatus($this->record, $data['status'], auth()->user(), $data['note'] ?? null), 'Status updated');
                    $this->refreshRecord();
                }),
            Action::make('assign')
                ->label(fn () => $this->record->assigned_to ? 'Reassign' : 'Assign')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->authorize('assign')
                ->schema([
                    Select::make('assigned_to')->label('Assign to')->required()->searchable()
                        ->options(fn () => User::assignable()->orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => $this->record->assigned_to),
                    Textarea::make('note')->rows(2)->maxLength(500),
                ])
                ->action(function (array $data) {
                    Guarded::run(fn () => app(LeadWorkflow::class)->assign($this->record, (int) $data['assigned_to'], auth()->user(), $data['note'] ?? null), 'Lead assignment updated');
                    $this->refreshRecord();
                }),
        ];
    }

    private function refreshRecord(): void
    {
        $this->record->refresh();
    }
}
