<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Filament\Support\Guarded;
use App\Models\FollowUp;
use App\Services\LeadWorkflow;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Follow-ups are scheduled from the lead header; here they are completed or cancelled, never deleted. */
class FollowUpsRelationManager extends RelationManager
{
    protected static string $relationship = 'followUps';

    protected static ?string $title = 'Follow-ups';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(fn ($query) => $query->with('assignee:id,name'))
            ->columns([
                TextColumn::make('title')->wrap()->description(fn (FollowUp $record) => $record->description),
                TextColumn::make('assignee.name')->label('Assigned to')->placeholder('Unassigned'),
                TextColumn::make('due_at')->label('Due')->dateTime('d M Y H:i')->sortable()
                    ->color(fn (FollowUp $record) => $record->isOverdue() ? 'danger' : null),
                // Overdue is derived from status + due date, not stored.
                TextColumn::make('display_status')->label('Status')->badge()
                    ->state(fn (FollowUp $record) => $record->isOverdue() ? 'overdue' : $record->status)
                    ->color(fn ($state) => match ($state) {
                        'overdue' => 'danger', 'completed' => 'success', 'cancelled' => 'gray', default => 'warning',
                    })
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
                TextColumn::make('completed_at')->label('Completed')->dateTime('d M Y H:i')->placeholder('-'),
            ])
            ->defaultSort('due_at', 'asc')
            ->filters([
                SelectFilter::make('status')->options(['pending' => 'Pending', 'completed' => 'Completed', 'cancelled' => 'Cancelled']),
            ])
            ->recordActions([
                Action::make('complete')->label('Complete')->icon('heroicon-o-check-circle')->color('success')
                    ->requiresConfirmation()->authorize('update')
                    ->visible(fn (FollowUp $record) => $record->status === 'pending')
                    ->action(fn (FollowUp $record) => Guarded::run(
                        fn () => app(LeadWorkflow::class)->completeFollowUp($record, auth()->user()), 'Follow-up completed')),
                Action::make('cancel')->label('Cancel')->icon('heroicon-o-x-circle')->color('gray')
                    ->requiresConfirmation()->authorize('update')
                    ->visible(fn (FollowUp $record) => $record->status === 'pending')
                    ->action(fn (FollowUp $record) => Guarded::run(
                        fn () => app(LeadWorkflow::class)->cancelFollowUp($record, auth()->user()), 'Follow-up cancelled')),
            ]);
    }
}
