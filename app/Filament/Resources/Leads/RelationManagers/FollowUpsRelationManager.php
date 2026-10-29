<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Filament\Support\FollowUpActions;
use App\Filament\Support\Status;
use App\Models\FollowUp;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Follow-ups are scheduled from the lead header; here they are progressed or closed, never deleted. */
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
                    ->color(fn ($state) => Status::FOLLOW_UP[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Status::label($state)),
                TextColumn::make('completed_at')->label('Completed')->dateTime('d M Y H:i')->placeholder('-'),
            ])
            ->defaultSort('due_at', 'asc')
            ->filters([
                SelectFilter::make('status')->options(Status::options(FollowUp::STATUSES)),
            ])
            ->recordActions(FollowUpActions::all());
    }
}
