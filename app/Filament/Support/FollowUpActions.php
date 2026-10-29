<?php

namespace App\Filament\Support;

use App\Models\FollowUp;
use App\Services\LeadWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;

/** The row actions for a follow-up task, shared by the lead tab and the "My follow-ups" queue. */
final class FollowUpActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        $active = fn (FollowUp $record) => $record->isActive();

        return [
            Action::make('start')->label('Start')->icon('heroicon-o-play')->color('info')
                ->authorize('update')
                ->visible(fn (FollowUp $record) => $record->status === 'open')
                ->action(fn (FollowUp $record) => Guarded::run(fn () => app(LeadWorkflow::class)->startFollowUp($record, auth()->user()), 'Follow-up started')),
            Action::make('complete')->label('Complete')->icon('heroicon-o-check-circle')->color('success')
                ->authorize('update')->visible($active)
                ->schema([Textarea::make('note')->label('Completion note (optional)')->rows(2)->maxLength(1000)])
                ->action(fn (FollowUp $record, array $data) => Guarded::run(
                    fn () => app(LeadWorkflow::class)->completeFollowUp($record, auth()->user(), $data['note'] ?? null), 'Follow-up completed')),
            Action::make('reschedule')->label('Reschedule')->icon('heroicon-o-calendar')->color('gray')
                ->authorize('update')->visible($active)
                ->schema([DateTimePicker::make('due_at')->label('New due time')->required()->seconds(false)->minDate(now()->subMinute())])
                ->action(fn (FollowUp $record, array $data) => Guarded::run(
                    fn () => app(LeadWorkflow::class)->rescheduleFollowUp($record, $data['due_at'], auth()->user()), 'Follow-up rescheduled')),
            Action::make('cancel')->label('Cancel')->icon('heroicon-o-x-circle')->color('gray')
                ->authorize('update')->visible($active)
                ->schema([Textarea::make('note')->label('Reason (optional)')->rows(2)->maxLength(1000)])
                ->action(fn (FollowUp $record, array $data) => Guarded::run(
                    fn () => app(LeadWorkflow::class)->cancelFollowUp($record, auth()->user(), $data['note'] ?? null), 'Follow-up cancelled')),
        ];
    }
}
